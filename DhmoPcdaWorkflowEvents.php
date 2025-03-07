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

final class DhmoPcdaWorkflowEvents
{
    // Triggers before the main document is deleted
    const DOCUMENT_DELETED = 'dhmopcdaworkflow.document.deleted';

    // Triggers when task is created
    const DOCUMENT_CREATED = 'dhmopcdaworkflow.document.created';

    // Triggers when a task is updated
    const DOCUMENT_UPDATE = 'dhmopcdaworkflow.document.update';

    // Triggers before an task is deletedj
    const TASK_DELETED = 'dhmopcdaworkflow.task.deleted';

    // Triggers when an task is created
    const TASK_CREATED = 'dhmopcdaworkflow.task.created';

    // Triggers when a task is updated
    const TASK_UPDATE = 'dhmopcdaworkflow.task.update';
}
