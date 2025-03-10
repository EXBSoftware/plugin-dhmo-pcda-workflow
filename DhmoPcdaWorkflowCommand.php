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
use EXB\IM\Bridge\Number;
use EXB\Kernel;
use EXB\Kernel\Database;
use EXB\Kernel\Document\AbstractDocument;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Queue\AbstractCommand;
use EXB\Kernel\Queue\Command\CommandQueue;
use EXB\R4\Config;
use EXB\Kernel\Plugin\PluginManager;
use EXB\Kernel\Document\Model\ModelAbstract;
use \EXB\User;

class DhmoPcdaWorkflowCommand extends AbstractCommand
{
    const ACTION_CREATE_TASK = 'create_task';
    const ACTION_REMOVE_TASK = 'remove_task';

    static $configBase = 'plugin:dhmopcdaworkflow';

    public function process(array $message)
    {
        $document = Factory::fetch(Modules::MODULE_INCIDENT, $message['itemId'], false);
        $is_new = $message['is_new'];
        $data = $message['data'];


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

        // check if we're interested in this category
        if (in_array($categoryId, DhmoPcdaWorkflow::getTargetCategories()) == false) {
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
                    'station' => $station,
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
                        'station' => $station,
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

        // Inform
        CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
            'event' => $is_new ? DhmoPcdaWorkflowEvents::DOCUMENT_CREATED : DhmoPcdaWorkflowEvents::DOCUMENT_UPDATE,
            'itemId' => $document->getId()
        ]);
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

    // Returns IM user id
    private function getTaskReportedByUserId(AbstractDocument $station, $roleId) {
        $model = $station->getModel();

        switch($roleId) {
            case '405': // Operations
                return Config::get(DhmoPcdaWorkflow::$configBase.'.operations_userid', 322);
                break;
            case '404': // regio manager
                $regionManagerField = $model->getFieldByAlias('regioman');
                if ($regionManagerField) {

                    $users = $regionManagerField->getType()->getProxy()->getData();
                    if (!is_array($users)) $users = [$users];

                    if (sizeof($users) == 0) {
                        Kernel::getLogger()->addInfo(DhmoPcdaWorkflow::$configBase. ': No regio manager found while the station wants it');
                        return -1;
                    }

                    $regioManager = new User($users[0]);

                    return $regioManager->getProductUser('im')->getId();
                }

                break;
            case '403': // tankstation
                $stationField = $model->getFieldByAlias('statuser');
                if ($stationField) {
                    $users = $stationField->getType()->getProxy()->getData();
                    if (!is_array($users)) $users = [$users];

                    if (sizeof($users) == 0) {
                        Kernel::getLogger()->addInfo(DhmoPcdaWorkflow::$configBase. ': No station found while the station wants it');
                        return -1;
                    }

                    $station = new User($users[0]);

                    return $station->getProductUser('im')->getId();
                }
                break;
            default:
                Kernel::getLogger()->addInfo(DhmoPcdaWorkflow::$configBase. ': Unknown role id to report to', [$roleId]);
        }

        return -1;
    }

    // Execute the action plan to create/delete tasks
    private function executeActionPlan($actionPlan)
    {
        PluginManager::getPlugin('mobile')->disable();
        $db = Database::getInstance();

        // The category id when creating new tasks
        $taskCategoryId = DhmoPcdaWorkflow::getTaskCategoryId();

        // Procedure table id
        $procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

        /** @var \EXB\IM\Bridge\Category $category **/
        $category = \EXB\IM\Bridge\Category::getInstance($taskCategoryId);


        \EXB\Kernel::getLogger()->addInfo('Performing actionplan with count', [sizeof($actionPlan)]);

        foreach ($actionPlan as $index => $plan) {

            if ($plan['action'] == self::ACTION_CREATE_TASK) {
                /**
                 * Create the task and set the category
                 * @var \EXB\IM\Bridge\Documents\Incident $task
                 */
                $task = Factory::create(Modules::MODULE_INCIDENT);
                $task->getModel()->disableFeature(ModelAbstract::FEATURE_ROUTING);
                $task->getModel()->disableFeature(ModelAbstract::FEATURE_SORT);
                $task->getModel()->disableFeature(ModelAbstract::FEATURE_SECURITYLEVEL);
                $task->getModel()->disableFeature(ModelAbstract::FEATURE_FIXEDFIELDPROPERTIES);

                $task->setCategory($category);
                $task->setName($plan['value']['Task']);
                $task->save();

                // Add reference between the parent document and the source (question) of the reference
                $task->addReference($plan['reference']);

                // Allocate number
                Number::allocate($task);

                // Set general fields
                $statusId = Config::get(static::$configBase . '.task_registered_status', 3);
                $task->setField('Status_ID', $statusId);
                $task->setField('report_date', (new \DateTime())->format(\DateTime::ATOM));
                $task->setField('Ingevoerd_door', \EXB\User::getCurrent()->getProductUser('IM')->getId());
                $targetUser = $this->getTaskReportedByUserId($plan['station'], $plan['value']['Role']);
                if ($targetUser != -1) {
                    $task->setField('Gemelddoor_ID', $targetUser); // IM Target user
                }
                $task->setField('Rubriek_id', $category->getId());

                $task->save();


                // Connect the category to the task
                // TODO add category field to the task model
                // TODO Is this needed? Tasks are created as a child of the category (see document hierarchy)
                //      You can easily do a $document->getParent() for the Category document
                $categoryDocument = Factory::fetch(
                    Modules::MODULE_CATEGORY,
                    $plan['reference']->getCategory()->getId()
                );
                $task->addReference($categoryDocument);

                $values = [
                    [$task->getModel()->getFieldByAlias('qid')->getId(), $plan['field']->getId()],
                    [$task->getModel()->getFieldByAlias('TaskCat')->getId(), $plan['value']['TaskCat']],
                    [$task->getModel()->getFieldByAlias('Role')->getId(), $plan['value']['Role']],
                    [$task->getModel()->getFieldByAlias('Task')->getId(), $plan['value']['Task']],
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

                CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
                    'event' => DhmoPcdaWorkflowEvents::TASK_CREATED,
                    'itemId' => $task->getId()
                ]);

                // Force direct index
                $task->performIndex();
            } else if ($plan['action'] == self::ACTION_REMOVE_TASK) {
                /** @var Incident $reference */
                $reference = $plan['reference'];

                // Delete should be performed synchronously
                CommandQueue::execute(DhmoPcdaWorkflowEventCommand::class, [
                    'event' => DhmoPcdaWorkflowEvents::TASK_DELETED,
                    'itemId' => $reference->getId()
                ]);

                $reference->delete();
            }
        }

        PluginManager::getPlugin('mobile')->enable();
    }
}
