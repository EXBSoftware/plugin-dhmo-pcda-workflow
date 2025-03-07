<?php

namespace EXB\Plugin\Custom\DhmoPcdaWorkflow;

use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use EXB\IM\Bridge\AccessInterface\Adapter\ServiceDesk;
use EXB\IM\Bridge\Modules;
use EXB\User;
use EXB\Kernel\Document\AbstractDocument;

class DhmoPcdaWorkflowAccessPlugin extends ServiceDesk
{
    public function authorize(AbstractDocument $document, $mode, User\UserInterface $user)
    {
        $pUser = $user->getProductUser('im');
        if ($user->hasAdministratorRights() || $pUser->isAdministrator()) {
            return true;
        }

        $allowedModules = [
            Modules::MODULE_INCIDENT
        ];

        if (in_array($document->getModule()->getId(), $allowedModules) == false) {
            return false;
        }

        // Check for task access
        if ($document->getCategory()->getId() == DhmoPcdaWorkflow::getTaskCategoryId()) {
            $recipient   = $document->getModel()->getField('receipient')->getValue();
            $monitoredBy = $document->getModel()->getField('monitoredby')->getValue();

            if (array_key_exists('id', $recipient)) {
                if ($pUser->getId() == $recipient['id']) {
                    return true;
                }
            } elseif (array_key_exists('id', $monitoredBy)) {
                if ($pUser->getId() == $monitoredBy['id']) {
                    return true;
                }
            } elseif (array_key_exists(0, $recipient)) {
                if (in_array($pUser->getId(), $recipient)) {
                    return true;
                }
            } elseif (array_key_exists(0, $monitoredBy)) {
                if (in_array($pUser->getId(), $monitoredBy)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getIndexFilter(): \Elastica\Query\AbstractQuery
    {
        $filter = new BoolQuery;
        $filter->addMust((new Term)->setTerm('_document.module', 'im'));

        // Logged in exb user
        $user = User::getCurrent();
        $pUser = $user->getProductUser('im');

        if ($user == false) {
            $filter->addMust((new Term)->setTerm('_document.id', -1));
            return $filter;
        }

        // When administrator, return everything
        if ($user->hasAdministratorRights() || $pUser->isAdministrator()) {
            return $filter;
        } else {
            $accessFilter = new BoolQuery;
            $accessFilter->addMust(
                (new Terms)->setTerms('category.id', [DhmoPcdaWorkflow::getTaskCategoryId()]));

            $accessFilter->addShould((new Term)->setTerm('registeredby.id', $pUser->getId()));
            $accessFilter->addShould((new Term)->setTerm('loggedinuser.id', $pUser->getId()));

            $filter->addMust($accessFilter);

            return $filter;
        }
    }
}
