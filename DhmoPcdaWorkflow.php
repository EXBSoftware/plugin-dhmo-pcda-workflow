<?php

namespace EXB\Plugin\Custom\DhmoPcdaWorkflow;

use EXB\Kernel\Plugin\AbstractPlugin;
use EXB\Kernel\Document\DocumentEvents;

class DhmoPcdaWorkflow extends AbstractPlugin
{
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
			DocumentEvents::DOCUMENT_PRE_SHOW => ['onDocumentShow', 0],
		];
	}

	public function onDocumentShow()
	{
		die("SHOW EVENT TRIGGERED");
	}
}
