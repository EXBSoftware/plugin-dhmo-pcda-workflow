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

use EXB\IM\Bridge\Modules;
use EXB\Kernel\Document\AbstractDocument;
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
			'mobile_document_created' => ['onMobileSave', 0]
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
