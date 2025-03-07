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
use EXB\IM\Bridge\Email\Template;
use EXB\IM\Bridge\Modules;
use EXB\IM\Bridge\User\AnonymouseUser;
use EXB\Kernel;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Queue\AbstractCommand;
use EXB\R4\Config;

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
        $taskCategoryId = DhmoPcdaWorkflow::getTaskCategoryId();

		switch ($event) {
			// The existing task has been changed
			case DhmoPcdaWorkflowEvents::TASK_UPDATE: {
				$task = $document;
				$refs = $task->getReferences();

				$refs->filter(function($document) use($taskCategoryId) {
					return $document->getModule()->getId() == Modules::MODULE_INCIDENT &&
						$document->getCategory()->getId() != $taskCategoryId;
				});

				if (sizeof($refs) == 0) {
					Kernel::getLogger()->addWarning(DhmoPcdaWorkflow::$configBase . ': Could not determine main incident for task', ['itemId' => $document->getId()]);
				}

				/** @var Incident $main */
				$main = $refs[0];

				$tasks = $main->getReferences()->filter(function($document) use($taskCategoryId) {
					return $document->getModule()->getId() == Modules::MODULE_INCIDENT && $document->getCategory()->getId() == $taskCategoryId;
				});

				$uncompletedCount = 0;
				$completed_task_id  = Config::get(DhmoPcdaWorkflow::$configBase . '.completed_task_status_id', 157);
				foreach ($tasks as $task) {
					if ($task->getField('status_id') != $completed_task_id) {
						$uncompletedCount++;
					}
				}

				if ($uncompletedCount == 0) {
					$categoryTemplateIds = [91 => 17,92 => 18]; // 91 = HACCP, 92 = Kwaliteit
					$templateId = $categoryTemplateIds[$document->getCategory()->getId()];
					$template = new Template($main, $templateId);

					$notification = new \EXB\IM\Bridge\Message\Format\Notification($main);
					$notification
						->setBody($template->getBody())
						->setSubject($template->getSubject());

					$user = $main->getReportedBy();
					$notification->setRecipient($user->getR4User());
					$notification->send();

					// Update status
					$main->setField('status_id', Config::get(DhmoPcdaWorkflow::$configBase . '.completed_status_id', 15));
					$main->save();
				}
				break;
			}
			case DhmoPcdaWorkflowEvents::TASK_CREATED: {
				// 14 => to emloyee
				$template = new Template($document, 14);
				$notification = new \EXB\IM\Bridge\Message\Format\Notification($document);
				$notification
					->setBody($template->getBody())
					->setSubject($template->getSubject());
				$notification->setRecipient($document->getReportedBy()->getR4User());
				$notification->send();

				// 15 => department field
				foreach ($document->getModel()->getFieldByAlias('Inform')->getValue() as $department) {
					$template = new Template($document, 15);
					$notification = new \EXB\IM\Bridge\Message\Format\Notification($document);
					$notification
						->setBody($template->getBody())
						->setSubject($template->getSubject());
					$user = new AnonymouseUser();
					$user->setEmail($department->getModel()->getFieldByAlias('depmail')->getIndex()->getValue());
					$notification->setRecipient();
					$notification->send();
				}
				break;
			}
			case DhmoPcdaWorkflowEvents::TASK_DELETED: {
				$template = new Template($document, 20);
				$notification = new \EXB\IM\Bridge\Message\Format\Notification($document);
				$notification
					->setBody($template->getBody())
					->setSubject($template->getSubject());
				$notification->setRecipient($document->getReportedBy()->getR4User());
				$notification->send();

				break;
			}

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
			default: {
				Kernel::getLogger()
					->addWarning(DhmoPcdaWorkflow::$configBase . ': Unknown event', ['event' => $event]);
			}
		}
	}
}
