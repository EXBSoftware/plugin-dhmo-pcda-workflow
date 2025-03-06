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
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Queue\AbstractCommand;

class DhmoPcdaWorkflowEventCommand extends AbstractCommand
{
	public function process(array $message)
	{
		if (array_key_exists('event', $message) == false ||
			array_key_exists('itemId', $message) == false
		) {
			Kernel::getLogger()->addWarning(DhmoPcdaWorkflow::$configBase . ': Cannot process event, not enough parameters given', $message);
		}

		$event = $message['event'];

		/** @var Incident $document */
		$document = Factory::fetch(Modules::MODULE_INCIDENT, $message['itemId']);

		if ($document && $document->exists()) {
			$this->handleEvent($event, $document);
		} else {
			Kernel::getLogger()->addWarning(DhmoPcdaWorkflow::$configBase . ': Cannot process event, document not found', $message);
		}
	}

	public function handleEvent($event, Incident $document) {
		switch ($event) {
			case DhmoPcdaWorkflowEvents::DOCUMENT_CREATED:
			{
				break;
			}
			case DhmoPcdaWorkflowEvents::DOCUMENT_UPDATE: {
				break;
			}
			case DhmoPcdaWorkflowEvents::DOCUMENT_DELETED: {
				break;
			}
			case DhmoPcdaWorkflowEvents::TASK_DELETED: {
				break;
			}
			case DhmoPcdaWorkflowEvents::TASK_DELETED: {
				break;
			}
			default: {
				Kernel::getLogger()
					->addWarning(DhmoPcdaWorkflow::$configBase . ': Unknown event', ['event' => $event]);
			}
		}
	}
}