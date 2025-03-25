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
use EXB\Kernel\Database;
use EXB\Kernel\Document\Factory;
use EXB\Kernel\Environment;
use EXB\Kernel\Message\Hub;
use EXB\Kernel\Plugin\PluginManager;
use EXB\Kernel\Queue\AbstractCommand;
use EXB\R4\Config;
use EXB\IM\Bridge\Webform\Output\Pdf;
use EXB\IM\Bridge\Number;
use EXB\User\External;

class DhmoPcdaWorkflowEventCommand extends AbstractCommand
{
	public function process(array $message)
	{
		PluginManager::getPlugin('mobile')->disable();
		if (
			array_key_exists('event', $message) == false ||
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
		PluginManager::getPlugin('mobile')->enable();
	}

	public function handleEvent($event, Incident $document)
	{
		$add_pdf  = Config::get(DhmoPcdaWorkflow::$configBase . '.attach_pdf_emails', true);

		$db = Database::getInstance();

		$taskCategoryId = DhmoPcdaWorkflow::getTaskCategoryId();

		switch ($event) {
				// The existing task has been changed
			case DhmoPcdaWorkflowEvents::TASK_UPDATE: {
					$task = $document;

					$sql = $db->select()->from('r5_references', ['targetId'])
						->where('sourceId = ?', $task->getId())
						->where('sourceModule = ?', $task->getModule()->getId())
						->where('targetModule = ?', Modules::MODULE_INCIDENT);
					$mainId = $db->fetchOne($sql);

					/** @var Incident $main */
					$main = Factory::fetch(Modules::MODULE_INCIDENT, $mainId);

					if ($main->exists()) {
						$otherTasks = $main->getReferencesByClassname(
							Incident::class,
							Kernel\Document\Reference\Reference::DIRECTION_BOTH
						);

						$completed_task_id  = Config::get(DhmoPcdaWorkflow::$configBase . '.completed_task_status_id', 157);
						$uncompletedCount = 0;
						foreach ($otherTasks as $oTask) {
							if ($oTask->getCategory()->getId() == $taskCategoryId && $oTask->getField('status_id') != $completed_task_id) {
								$uncompletedCount++;
							}
						}

						if ($uncompletedCount == 0) {

							if ($main->getField('status_id') != 3) {
								$categoryTemplateIds = [
									'91' => 17,
									'92' => 18
								]; // 91 = HACCP, 92 = Kwaliteit
								$templateId = $categoryTemplateIds[$main->getCategory()->getId()];
								$template = new Template($main, $templateId);

								$notification = new \EXB\Kernel\Message\Format\Email($main);
								$notification
									->setBody($template->getBody())
									->setSubject($template->getSubject());

								$user = $main->getReportedBy();
								$notification->setRecipient($user->getR4User());

								if ($add_pdf) {
									$notification->addAttachment((new Number($main))->get() . ".pdf", (new Pdf($main))->generate());
								}

								Hub::send($notification);
							}

							//  NOTE This is handles by the onMailevent?
							$main->setField('status_id', Config::get(DhmoPcdaWorkflow::$configBase . '.completed_status_id', 15));
							$main->save();
						}
					}
					break;
				}
			case DhmoPcdaWorkflowEvents::TASK_CREATED: {
					// Get main incident
					$sql = $db->select()->from('r5_references', ['targetId'])
						->where('sourceId = ?', $document->getId())
						->where('sourceModule = ?', $document->getModule()->getId())
						->where('targetModule = ?', Modules::MODULE_INCIDENT);
					$mainId = $db->fetchOne($sql);
					/** @var Incident $main */
					$main = Factory::fetch(Modules::MODULE_INCIDENT, $mainId);

					// Fetch fotos
					$questionIdField = $document->getModel()->getFieldByAlias('qid');
					$images = [];
					if ($questionIdField) {
						$questionId = $questionIdField->getValue();
						$sql = $db->select()->from('collab_mobileconnector_files', ['id', 'file'])
							->where('bind = ?', $questionId)
							->where('moduleid = ?', Modules::MODULE_INCIDENT)
							->where('itemid = ?', $main->getId());
						foreach ($db->fetchAll($sql) as $index => $image) {
							$filename = Environment::getTemporaryDirectory() . DIRECTORY_SEPARATOR . $image['id'];
							file_put_contents($filename, $image['file']);

							$images[] = [
								'name' => sprintf('Foto - %d.png', ++$index),
								'path' => $filename
							];
						}
					}

					Kernel::getLogger()
						->addInfo(DhmoPcdaWorkflow::$configBase . ': Task created, sending emails', ['photo_count' => sizeof($image)]);

					// 14 => to emloyee
					$template = new Template($document, 14);
					$notification = new \EXB\Kernel\Message\Format\Email($document);
					$notification
						->setBody($template->getBody())
						->setSubject($template->getSubject())
						->addAttachments($images);

					$user = $document->getReportedBy()->getR4User();

					Kernel::getLogger()
						->addInfo(DhmoPcdaWorkflow::$configBase . ': Sending employee (14) email', [
							'email' => $user->getEmail(),
							'subject' => $notification->getSubject(),
							'photos' => sizeof($notification->getAttachments())
						]);

					$notification->setRecipient($user);

					if ($add_pdf) {
						$notification->addAttachment((new Number($document))->get() . ".pdf", (new Pdf($document))->generate());
					}

					// Check for CC
					$sql = $db->select()->from('cim_variabele_velden_entries', ['procedureId' => 'klacht_id'])
						->where('veld_id = ?','3724')
						->where('waarde = ?',  $document->getModel()->getField('var3740')->getValue());
					$procedureId = $db->fetchOne($sql);

					// Get the procedure
					$document = Factory::fetch('table_62', $procedureId);
					$model = $document->getModel();

					$cc_addition = [];
					foreach(['mInform', 'uInform'] as $fieldName)  {
						$field = $model->getFieldByAlias($fieldName);
						foreach ($field->getValue() as $department) {
							$value = $department->getModel()->getFieldByAlias('depmail')->getValue();
							if (\EXB\Validation\Email::isValid($value)) {
								$cc_addition[] = $value;
							}
						}
					}

					$notification->setExternalReceipients(array_map(function ($recipient) {
						return new External($recipient, $recipient);
					}, $cc_addition), \EXB\Kernel\Message\Message::TYPE_CC);

					Hub::send($notification);

					// Clear temporary images
					foreach ($images as $image) {
						if (is_file($image['path'])) unlink($image['path']);
					}

					break;
				}
			case DhmoPcdaWorkflowEvents::TASK_DELETED: {
					$template = new Template($document, 20);
					$notification = new \EXB\Kernel\Message\Format\Email($document);
					$notification
						->setBody($template->getBody())
						->setSubject($template->getSubject());
					$notification->setRecipient($document->getReportedBy()->getR4User());

					if ($add_pdf) {
						$notification->addAttachment((new Number($document))->get() . ".pdf", (new Pdf($document))->generate());
					}

					Hub::send($notification);
					break;
				}

			case DhmoPcdaWorkflowEvents::DOCUMENT_CREATED: {
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
