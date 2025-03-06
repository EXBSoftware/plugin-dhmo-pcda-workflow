<?php

/**
 * EXB R5 - Business suite
 * Copyright (C) EXB Software 2025 - All Rights Reserved
 *
 * This file is part of EXB Software Platform.
 *
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 *
 * @author Emiel van Goor <e.goor@exb-software.com>
 */

declare(strict_types=1);

namespace EXB\Plugin\Custom\DhmoPcdaWorkflow;

use EXB\IM\Bridge\Documents\Incident;
use EXB\IM\Bridge\Modules;
use EXB\Kernel;
use EXB\Kernel\Database;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Queue\AbstractCommand;
use EXB\Kernel\Queue\Command\CommandQueue;
use EXB\R4\Config;

class DhmoPcdaWorkflowCommand extends AbstractCommand
{
    const ACTION_CREATE_TASK = 'create_task';
    const ACTION_REMOVE_TASK = 'remove_task';

    static $configBase = 'plugin:dhmopcdaworkflow';

    public function process(array $message)
    {
        $document = Factory::fetch(Modules::MODULE_INCIDENT, $message['itemId']);
        $is_new = $message['is_new'];
        $data = $message['data'];

		CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
			'event' => $is_new ? DhmoPcdaWorkflowEvents::DOCUMENT_CREATED : DhmoPcdaWorkflowEvents::DOCUMENT_UPDATE,
			'itemId' => $document->getId()
		]);

        $this->onSave($document, $is_new, $data);
    }

    public function onSave($document, $is_new, $data)
    {
        $db = Database::getInstance();

        Kernel::getLogger()->addNotice(
            self::$configBase . ': Get new save request, is it new? ' . $is_new ? ('YES (id:' . $document->getId() . ')') : 'NO'
        );

        // Procedure table id
        $procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

        $categoryId = $document->getCategory()->getId();
        $targetCategories = array_map(
            'trim',
            explode(',', Config::get(self::$configBase . '.categoryId', '91,92'))
        );

        // check if we're interested in this category
        if (in_array($categoryId, $targetCategories) == false) {
            Kernel::getLogger()->addNotice(self::$configBase . ': We\'re not interested in categoryId ' . $categoryId);
        }

        // Get station
        $stationField = $document->getModel()->getFieldByAlias('station');
        if (!$stationField) {
            Kernel::getLogger()->addWarning(self::$configBase . ': category does not have a station field');
            return;
        }

        $stationId = $stationField->getIndex()->getIndexValue()['id'];
        if ($stationId == -1) {
            Kernel::getLogger()->addWarning(self::$configBase . ': station id is -1');
            return;
        }

        $sql = $db->select()->from('cim_variabele_velden', ['id', 'params' => 'zoekveld'])
            ->where('veldtype_id = ?', '2010')
            ->where('catid = ?', $categoryId)
            ->where('moduleid = ?', Modules::MODULE_INCIDENT)
            ->where('deleted = ?', 'N');

        // Get all fields in this category we're interested in
        $fields = array_filter(
            array_map(function ($row) use ($data) {
                $fieldId = sprintf('var%s', $row['id']);
                $value = $data[$fieldId];

                $params = (array)json_decode($row['params']);

                unset($row['params']);
                unset($row['id']);

                return array_merge($row, [
                    'id' => $fieldId,
                    'enabled' => trim($params['mandatory_on']) != '',
                    'is_negative' => $value == $params['mandatory_on'],
                    'value' => $value
                ]);
            }, $db->fetchAll($sql)),
            function ($field) {
                return $field['enabled'] == true;
            }
        );

        // Station details
        $station = Factory::fetch(
            sprintf('table_%d', $stationField->getType()->getOptions()['tableId']),
            $stationId
        );
        $is_stations_manned = strtolower($station->getModel()->getFieldByAlias('bemand')->getValue()) == 'ja';

        // Procedure details
        $sql = $db->select()->from('cim_variabele_velden', ['id'])
            ->where('alias = ?', 'qalias')
            ->where('onderdeel_id = ?', $procedureTableId);

        // This is the id of the field which selects the id of the field of the incident
        $procedureFieldId = $db->fetchOne($sql);

        // Generate actionplan
        $actionPlan = [];
        foreach ($fields as $field) {
            // Get the id of the procedure
            $sql = $db->select()->from('cim_variabele_velden_entries', ['procedureId' => 'klacht_id'])
                ->where('veld_id = ?', $procedureFieldId)
                ->where('waarde = ?',  $field['id']);
            $procedureId = $db->fetchOne($sql);
            $details = $this->getProcedureDetails($is_stations_manned, $procedureId);

            if ($is_new) { // We're new, create new tasks ony
                if ($field['enabled'] == false) continue;

                // No need to create any action
                if ($field['is_negative'] == false) continue;

                $actionPlan[] = [
                    'reference' => $document,
                    'field' => $document->getModel()->getField($field['id']),
                    'action' => self::ACTION_CREATE_TASK,
                    'value' => $details
                ];
            } else {
                $tasks = $document->getReferencesByClassname(
                    Incident::class,
                    Kernel\Document\Reference\Reference::DIRECTION_BOTH,
                    $field['id']
                );

                if (sizeof($tasks) == 0 && $field['is_negative']) {
                    // Create new task
                    $actionPlan[] = [
                        'reference' => $document,
                        'field' => $document->getModel()->getField($field['id']),
                        'action' => self::ACTION_CREATE_TASK,
                        'value' => $details
                    ];
                } else {
                    // Remove task because answer if not negative
                    if ($field['is_negative'] == false) {
                        $actionPlan[] = [
                            'reference' => $tasks[0],
                            'field' => $document->getModel()->getField($field['id']),
                            'action' => self::ACTION_REMOVE_TASK,
                        ];
                    }
                }
            }
        }

        $this->executeActionPlan($actionPlan);
    }

    private function getProcedureDetails($is_manned, $procedureId)
    {
        $db = Database::getInstance();
        $procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

        $fieldAliasses = ['TaskCat', 'Task', 'Role', 'Inform', 'Leadtime'];

        // Select the correct field prefix based on manned or unmanned
        $aliasPrefix = $is_manned ? 'm' : 'u';

        // Use these fields
        $aliasses = array_map(function ($id) use ($aliasPrefix) {
            return sprintf('%s%s', $aliasPrefix, $id);
        }, $fieldAliasses);

        $sql = $db->select()->from('cim_variabele_velden', ['id', 'alias'])
            ->where('onderdeel_id = ?', $procedureTableId)
            ->where("alias IN ('" . implode("','", $aliasses) . "')");
        $fields = [];
        foreach ($db->fetchAll($sql) as $row) {
            $fields[substr($row['alias'], 1)] = $row['id'];
        }

        $sql = $db->select()->from('cim_variabele_velden_entries', ['fieldId' => 'veld_id', 'value' => 'waarde'])
            ->where('klacht_id = ?', $procedureId)
            ->where("veld_id IN ('" . implode("','", array_values($fields)) . "')");

        $data = [];
        $alias = array_flip($fields);
        foreach ($db->fetchAll($sql) as $row) {
            $data[$alias[$row['fieldId']]] = $row['value'];
        }

        return $data;
    }

    // Execute the action plan to create/delete tasks
    private function executeActionPlan($actionPlan)
    {
        $db = Database::getInstance();

        // The category id when creating new tasks
        $taskCategoryId = Config::get(self::$configBase . '.taskCategory', 112);

        // Procedure table id
        $procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

        /** @var \EXB\IM\Bridge\Category $category **/
        $category = \EXB\IM\Bridge\Category::getInstance($taskCategoryId);

        foreach ($actionPlan as $plan) {
            if ($plan['action'] == self::ACTION_CREATE_TASK) {
                /**
                 * Create the task and set the category
                 * @var \EXB\IM\Bridge\Documents\Incident $task
                 */
                $task = Factory::create(Modules::MODULE_INCIDENT);
                $task->setCategory($category);
                $task->setName($plan['value']['Task']);
                $task->save();

                // Add reference between the parent document and the source (question) of the reference
                $task->addReference($plan['reference'], $plan['field']->getId());

                // Connect the category to the task
                // TODO add category field to the task model
                $categoryDocument = Factory::fetch(
                    Modules::MODULE_CATEGORY,
                    $plan['reference']->getCategory()->getId()
                );
                $task->addReference($categoryDocument);

                $values = [
                    [$task->getModel()->getFieldByAlias('qid')->getId(), $plan['field']->getId()],
                    [$task->getModel()->getFieldByAlias('TaskCat')->getId(), $plan['value']['TaskCat']],
                    [$task->getModel()->getFieldByAlias('responsible')->getId(), $plan['value']['Role']],
                    [$task->getModel()->getFieldByAlias('Inform')->getId(), $plan['value']['Inform']],
                ];

                foreach ($values as $value) {
                    list($fieldId, $value) = $value;

                    $data = [
                        'klacht_id' => $task->getId(),
                        'veld_id' => substr($fieldId, 3),
                        'i' => 0,
                        'waarde' => $value,
                        'languageId' => 'nl',
                        'moduleId' => Modules::MODULE_INCIDENT
                    ];

                    $db->insert('cim_variabele_velden_entries', $data);
                }

                // Doorlooptijd
                $db->update('cim_klachten', [
                    'Max_afhandel_datum' => $plan['value']['Leadtime']
                ], $db->quoteInto('ID = ?', $task->getId()));

                // Force direct index
                $task->performIndex();

				CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
					'event' =>DhmoPcdaWorkflowEvents::TASK_CREATED,
					'itemId' => $task->getId()
				]);

                break;
            } else if ($plan['action'] == self::ACTION_REMOVE_TASK) {
                /** @var Incident $reference */
                $reference = $plan['reference'];

				// Delete should be performed synchronously
				CommandQueue::execute(DhmoPcdaWorkflowEventCommand::class, [
					'event' =>DhmoPcdaWorkflowEvents::TASK_DELETED,
					'itemId' => $reference->getId()
				]);

                $reference->delete();
                break;
            }
        }
    }
}
