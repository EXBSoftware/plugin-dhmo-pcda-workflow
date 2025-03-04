<?php

namespace EXB\Plugin\Custom\DhmoPcdaWorkflow;

use EXB\IM\Bridge\Category;
use EXB\IM\Bridge\Modules;
use EXB\Kernel;
use EXB\Kernel\Database;
use EXB\Kernel\Document\AbstractDocument;
use EXB\Kernel\Plugin\AbstractPlugin;
use EXB\Kernel\Document\DocumentEvents;
use EXB\Kernel\Document\Event\DocumentEvent;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Document\Model\SaveHandler\Event\SaveEvent;
use EXB\Kernel\Document\Model\SaveHandler\SaveHandlerEvents;
use EXB\Kernel\Document\Model\SavePayload;
use EXB\R4\Config;

class DhmoPcdaWorkflow extends AbstractPlugin
{
	const ACTION_CREATE_TASK = 'create_task';
	const ACTION_REMOVE_TASK = 'remove_task';

	static $configBase = 'plugin:dhmopcdaworkflow';

	/**
	 * {@inheritdoc}
	 */
	public function getPluginName()
	{
		return "dhmo.pdca-workflow";
	}

	/**
	 * Attach the access interface to events
	 *
	 * @static
	 * @access public
	 */
	static public function getSubscribedEvents()
	{
		return [
			DocumentEvents::DOCUMENT_PRE_SHOW	=> ['onDocumentShow', 0],
			DocumentEvents::DOCUMENT_PRE_DELETE		=> ['onDelete', 0],
			SaveHandlerEvents::SAVE				=> ['onSave', 0],
		];
	}

	public function onDocumentShow()
	{
		// die('Triggered');
	}

	public function onDelete(DocumentEvent $event) {
		$db = Database::getInstance();
		$document = $event->getDocument();

		if ($document->getModule()->getId() != Modules::MODULE_INCIDENT) {
			return;
		}

		$categoryId = $document->getCategory()->getId();
		$targetCategories = array_map(
			'trim', explode(',', Config::get(self::$configBase . '.categoryId', '91,92')));

		// check if we're interested in this category
		if (in_array($categoryId, $targetCategories) == false) return;

		// Delete all refrences (eg. tasks)
		foreach ($document->getReferences() as $ref) {
			/** @var AbstractDocument $ref **/

			$ref->delete();
		}
	}

	public function onSave(SaveEvent $event) {
		$db = Database::getInstance();
		$document = $event->getDocument();
		$data = $event->getPayload();

		if ($document->getModule()->getId() != Modules::MODULE_INCIDENT) {
			return;
		}

		// Defines if the incident is new or updating
		$is_new = $data->getParam('savehandler_request.itemid') == '-1';

		// Procedure table id
		$procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

		$categoryId = $document->getCategory()->getId();
		$targetCategories = array_map(
			'trim', explode(',', Config::get(self::$configBase . '.categoryId', '91,92')));

		// check if we're interested in this category
		if (in_array($categoryId, $targetCategories) == false) return;

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
			array_map(function($row) use($data) {
				$fieldId = sprintf('var%s', $row['id']);
				$value = $data->getField($fieldId);

				$params = (array)json_decode($row['params']);

				unset($row['params']);
				unset($row['id']);

				return array_merge($row, [
					'id' => $fieldId,
					'enabled' => $params['action'] == 'true',
					'is_negative' => $value == $params['mandatory_on'],
					'value' => $value
				]);
			}, $db->fetchAll($sql)),
			function($field) {
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
		// This is the id of the fielw which selects the id of the field of the incident
		$procedureFieldId = $db->fetchOne($sql);

		// Generate actionplan
		$actionPlan = [];
		foreach ($fields as $field) {
			if ($is_new) { // We're new, create new tasks ony
				if ($field['enabled'] == false) continue;

				// No need to create any action
				if ($field['is_negative'] == false) continue;

				// Get the id of the procedure
				$sql = $db->select()->from('cim_variabele_velden_entries', ['procedureId' => 'klacht_id'])
					->where('veld_id = ?', $procedureFieldId)
					->where('waarde = ?',  $field['id']);
				$procedureId = $db->fetchOne($sql);

				$details = $this->getProcedureDetails($is_stations_manned, $procedureId);

				$actionPlan[] = [
					'reference' => $document,
					'field' => $document->getModel()->getField($field['id']),
					'action' => self::ACTION_CREATE_TASK,
					'value' => $details
				];
			} else { // Update an existing, check the previous

			}
		}

		$this->executeActionPlan($actionPlan);
	}

	private function getProcedureDetails($is_manned, $procedureId) {
		$db = Database::getInstance();
		$procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

		$fieldAliasses = ['TaskCat', 'Task', 'Role', 'Inform', 'Leadtime'];

		// Select the correct field prefix based on manned or unmanned
		$aliasPrefix = $is_manned ? 'm' : 'u';

		// Use these fields
		$aliasses = array_map(function($id) use ($aliasPrefix) {
			return sprintf('%s%s', $aliasPrefix, $id);
		}, $fieldAliasses);

		$sql = $db->select()->from('cim_variabele_velden', ['id', 'alias'])
				   ->where('onderdeel_id = ?', $procedureTableId)
				   ->where("alias IN ('". implode("','", $aliasses) . "')");
		$fields = [];
		foreach($db->fetchAll($sql) as $row) {
			$fields[substr($row['alias'], 1)] = $row['id'];
		}

		$sql = $db->select()->from('cim_variabele_velden_entries', ['fieldId' => 'veld_id', 'value' => 'waarde'])
				   ->where('klacht_id = ?', $procedureId)
				   ->where("veld_id IN ('". implode("','", array_values($fields)) . "')");

		$data = [];
		$alias = array_flip($fields);
		foreach($db->fetchAll($sql) as $row) {
			$data[$alias[$row['fieldId']]] = $row['value'];
		}

		return $data;
	}

	// Execute the action plan to create/delete tasks
	private function executeActionPlan($actionPlan) {
		$db = Database::getInstance();

		// The category id when creating new tasks
		$taskCategoryId = Config::get(self::$configBase . '.taskCategory', 112);

		// Procedure table id
		$procedureTableId = Config::get(self::$configBase . '.procedure_tableid', 'table_62');

		/** @var \EXB\IM\Bridge\Category $category **/
		$category = \EXB\IM\Bridge\Category::getInstance($taskCategoryId);

		foreach ($actionPlan as $plan) {
			switch($plan['action']) {
				case self::ACTION_CREATE_TASK: {
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

					$payload = new SavePayload;
					$payload->addParam('categoryId', $category->getId());

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
				}

				case self::ACTION_REMOVE_TASK: {

				}
			}
		}
	}
}
