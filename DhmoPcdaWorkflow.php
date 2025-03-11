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

use EXB\Http\Request;
use EXB\IM\Bridge\Documents\Email;
use EXB\IM\Bridge\Documents\Incident;
use EXB\IM\Bridge\Mailbox\Event\MailEvent;
use EXB\IM\Bridge\Mailbox\MailboxEvents;
use EXB\IM\Bridge\Modules;
use EXB\Kernel;
use EXB\Kernel\Database;
use EXB\Kernel\Document\AbstractDocument;
use EXB\Kernel\Document\Event\ShowEvent;
use EXB\Kernel\Document\Field\Field;
use EXB\Kernel\Document\Field\FieldProxy;
use EXB\Kernel\Document\Field\Type\Images;
use EXB\Kernel\Plugin\AbstractPlugin;
use EXB\Kernel\Document\DocumentEvents;
use EXB\Kernel\Document\Event\DocumentEvent;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Document\Model\SaveHandler\Event\SaveEvent;
use EXB\Kernel\Queue\Command\CommandQueue;
use EXB\Plugin\Custom\DhmoPcdaWorkflow\DhmoPcdaWorkflowEventCommand;
use EXB\R4\Config;

class DhmoPcdaWorkflow extends AbstractPlugin
{
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
			DocumentEvents::DOCUMENT_PRE_DELETE	=> ['onDelete', 0],
			DocumentEvents::DOCUMENT_PRE_SAVE	=> ['onSave', 0],
			DocumentEvents::DOCUMENT_PRE_SHOW	=> ['onShow', 0],
			'mobile_document_created' => ['onMobileSave', 0],
			MailboxEvents::NEW_EMAIL => ['onMailReceived', 0]
		];
	}

	/**
	 * @return int[] array with category id's we're interested in
	 */
	static function getTargetCategories()
	{
		return array_map('trim', explode(',', Config::get(static::$configBase . '.categoryId', '91,92')));
	}

	/**
	 * @return int the category id
	 */
	static function getTaskCategoryId()
	{
		return Config::get(static::$configBase . '.taskCategory', 112);
	}

	/**
	 * Checks e-mails receive if it is an task e-mail.
	 * When it is a task e-mail, we need to save the status as 'completed' and
	 * check if all tasks in the questionaire have been completed. When all tasks
	 * in the current quesionair have been completed we need to change the status of the parent questionaire
	 *
	 * @param MailEvent $event
	 * @return void
	 * @throws \Zend_Db_Exception
	 */
	public function onMailReceived(MailEvent $event) {
		$db = Database::getInstance();

		/** @var Email $email **/
		$email = $event->getEmail();

		$task = Factory::fetch(
			Modules::MODULE_INCIDENT,
			$email->getField('incidentid')
		);

		Kernel::getLogger()->addInfo(static::$configBase . ': Received e-mail, checking if we need to process', [
			'itemId' => $task->getId(),
			'categoryId' => $task->getCategory()->getId(),
			'task_target_categoryId' => self::getTaskCategoryId(),
			'email_itemId' => $email->getId()
		]);

		if ($task->getCategory()->getId() == self::getTaskCategoryId()) {
			$statusId = Config::get(static::$configBase . '.task_completed_status', 157);
			$tasksCompletedStatusId = Config::get(static::$configBase . '.all_task_completed_status', 158);

			$task->setField('Status_ID', $statusId);
			$task->save();

			// update the field without performing save request (speed)
			$executedField = $task->getModel()->getFieldByAlias('date_executed');
			if ($executedField) {
				$fieldId = substr($executedField->getId(), 3);
				Database::delete('cim_variabele_velden_entries', [
					'klacht_id' => $task->getId(),
					'veld_id' => $fieldId
				]);

				$executedDate = new \DateTime;
				$data = [
					'klacht_id' => $task->getId(),
					'veld_id' => $fieldId,
					'i' => 0,
					'waarde' => sprintf('%sT00:00:00', $executedDate->format('Y-m-d')),
					'languageId' => 'nl',
					'moduleId' => Modules::MODULE_INCIDENT
				];
				$db->insert('cim_variabele_velden_entries', $data);
			}

			$task->performIndex();

			$ref = $task->getReferencesByClassname(
				Incident::class,
				\EXB\Kernel\Document\Reference\Reference::DIRECTION_BOTH
			);

			if (sizeof($ref) == 0) return;

			/** @var Incident $incident **/
			$incident = $ref[0];
			$otherTasks = $incident->getReferencesByClassname(
				Incident::class,
				Kernel\Document\Reference\Reference::DIRECTION_BOTH
			);

			$uncompletedTasks = 0;
			/** @var Incident $otherTask */
			foreach($otherTasks as $otherTask) {
				if ($otherTask->getField('Status_ID') != $statusId) {
					$uncompletedTasks++;
				}
			}

			if ($uncompletedTasks == 0) {
				Kernel::getLogger()
					->addInfo(self::$configBase . ': Received task reply ' . $uncompletedTasks . ' tasks remaining');
				$incident->setField('Status_ID', $tasksCompletedStatusId);
				$incident->save();
				$incident->performIndex();
			}
		} else {
		}
	}

	public function onShow(ShowEvent $event) {
		$document = $event->getDocument();
		$model = $event->getDocument()->getModel();
		$request = new Request;

		if ($document->getCategory()->getId() != self::getTaskCategoryId()) return;

		$proxy = new FieldProxy;
		$proxy->get(function() use($document, $request) {
			$db = Database::getInstance();

			$ref = $document->getReferencesByClassname(
				Incident::class,
				\EXB\Kernel\Document\Reference\Reference::DIRECTION_BOTH
			);

			if (sizeof($ref) == 0) return [];

			$incident = $ref[0];

			$questionIdField = $document->getModel()->getFieldByAlias('qid');
			$questionId = $questionIdField->getValue();

			$sql = $db->select()->from('collab_mobileconnector_files', ['id'])
				->where('bind = ?', $questionId)
				->where('moduleid = ?', Modules::MODULE_INCIDENT)
				->where('itemid = ?', $incident->getId());

			$imageId = $db->fetchOne($sql);
			if (!$imageId) return [];

			return [
				$request->getRootUrl() . "/public/products/qhse/index.php/plugin/r5.collaboration/connector?name=r5.im.mobileconnector&action=getPreview&id=" . $imageId
			];
		});

		$field = new Field('dhmo_pcda_images', 'foto', new Images($proxy));
		$field->setIsVariable(true);
		$page = $model->getPage(0);
		$page->addField($field);
		$field->setOrder(0.1);
		// Register on model. (This is a hack...)
		$model->all_fields['dhmo_pcda_images'] = $field;

	}

	public function onDelete(DocumentEvent $event)
	{
		$document = $event->getDocument();

		if ($document->getModule()->getId() != Modules::MODULE_INCIDENT) {
			return;
		}

		$categoryId = $document->getCategory()->getId();

		// check if we're interested in this category
		if (in_array($categoryId, self::getTargetCategories()) == false) return;

		CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
			'event' => DhmoPcdaWorkflowEvents::DOCUMENT_DELETED,
			'itemId' => $document->getId()
		]);

		// Delete all refrences (eg. tasks)
		foreach ($document->getReferences() as $ref) {
			/** @var AbstractDocument $ref **/

			// Delete should be performed synchronously
			CommandQueue::execute(DhmoPcdaWorkflowEventCommand::class, [
				'event' => DhmoPcdaWorkflowEvents::TASK_DELETED,
				'itemId' => $document->getId()
			]);

			$ref->delete();
		}
	}

	public function onSave(DocumentEvent $event)
	{
		$document = $event->getDocument();

		if ($document->getModule()->getId() != Modules::MODULE_INCIDENT) {
			return;
		}

		if ($document->getCategory()->getId() == DhmoPcdaWorkflow::getTaskCategoryId()) {
			if ($document->exists()) {
				CommandQueue::do(DhmoPcdaWorkflowEventCommand::class, [
					'event' => DhmoPcdaWorkflowEvents::TASK_UPDATE,
					'itemId' => $document->getId()
				]);
			}
		}
	}

	public function onMobileSave(SaveEvent $event)
	{
		$document = $event->getDocument();
		$data = $event->getPayload();

		if ($document->getModule()->getId() != Modules::MODULE_INCIDENT) {
			return;
		}

		// Fetch document without cache, trying to make sure it exists
		$document = Factory::fetch(Modules::MODULE_INCIDENT, $event->getDocument()->getId(), false);
		if ($document->exists() == false) {
			$document->save();
		}

		$is_new = $data->getParam('savehandler_request.itemid') == '-1';

		// Run task
		CommandQueue::do(DhmoPcdaWorkflowCommand::class, [
			'itemId' => $document->getId(),
			'is_new' => $is_new,
			'data' => $data->getFields()
		]);
	}
}
