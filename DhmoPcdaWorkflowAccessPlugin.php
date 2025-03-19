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

use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use EXB\IM\Bridge\AccessInterface\Adapter\ServiceDesk;
use EXB\IM\Bridge\Modules;
use EXB\Kernel\Database;
use EXB\User;
use EXB\Kernel\Document\AbstractDocument;
use EXB\Kernel\BI\PowerBI\Report\FilterBasic;

class DhmoPcdaWorkflowAccessPlugin extends ServiceDesk
{
    public function authorize(AbstractDocument $document, $mode, User\UserInterface $user)
    {
        $pUser = $user->getProductUser('im');
        if ($user->hasAdministratorRights() || $pUser->isAdministrator()) {
            return true;
        }

				$is_gasstation = $pUser->getRole()->getName() == 'Tankstation';

        $allowedModules = [
            Modules::MODULE_INCIDENT
        ];

        if (in_array($document->getModule()->getId(), $allowedModules) == false) {
            return false;
        }

				if (
					$document->getModule()->getId() == Modules::MODULE_INCIDENT &&
					$document->getCategory()->getId() == DhmoPcdaWorkflow::getTaskCategoryId()
				) {
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
						} else if ($is_gasstation) {
					$gasStationField = $user->getDocument()->getModel()->getFieldByAlias('stationtbl');

					if (!$gasStationField) return parent::authorize($document, $mode, $user);

					$gasStationId = $gasStationField->getIndex()->getIndexValue()['id'];

					$stationField = $document->getModel()->getFieldByAlias('station');
					if (!$stationField) return parent::authorize($document, $mode, $user);

					// Are with the target gas station?
					return $stationField->getIndex()->getIndexValue()['id'] == $gasStationId;
        } else {
					return parent::authorize($document, $mode, $user);
				}
    }

    public function getIndexFilter(): \Elastica\Query\AbstractQuery
    {
			$db = Database::getInstance();

        $filter = new BoolQuery;
        $filter->addMust((new Term)->setTerm('_document.module', 'im'));

        // Logged in exb user
        $user = User::getCurrent();
        $pUser = $user->getProductUser('im');

				$is_gasstation = $pUser->getRole()->getName() == 'Tankstation';

        if ($user == false) {
            $filter->addMust((new Term)->setTerm('_document.id', -1));
            return $filter;
        }

        // When administrator, return everything
        if ($user->hasAdministratorRights() || $pUser->isAdministrator()) {
            return $filter;
        } else if ($is_gasstation) {
					// get location field ids id

					$gasStationField = $user->getDocument()->getModel()->getFieldByAlias('stationtbl');

					if (!$gasStationField) return $filter;

					$gasStationId = $gasStationField->getIndex()->getIndexValue()['id'];

					// Filter on station field, each category has its own station field
					$stationsFilter = new BoolQuery;

					$sql = $db->select()->from('cim_variabele_velden', ['id', 'catid'])
						->where('alias = ?', 'station')
						->where('moduleid = ?', Modules::MODULE_INCIDENT)
						->where('deleted = ?', 'N');
					foreach ($db->fetchAll($sql) as $row) {
						$stationFilter = new BoolQuery;

						// The category needs to be this
            $stationFilter->addMust(
							(new Term)->setTerm('category.id', $row['catid']));

						// The station field should be this
						$stationFilter->addMust(
							(new Term)->setTerm(sprintf('var%d.id', $row['id']), $gasStationId));

						$stationsFilter->addShould(
							$stationFilter
						);
					}

					return $stationsFilter;

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



	/**
	 * Returns a filter based on categorie `access` according to IM AccessInterfaceAdapter
	 * @uses \EXB\IM\Bridge\Category
	 * @uses \EXB\Kernel\BI\PowerBI\Report\FilterBasic
	 * @return array <IBasicFilter>
	 */
	public function getDefaultFilter($user, $table = 'registrations', $column = 'incident_category')
	{
		$allCategories = \EXB\IM\Bridge\Category::getAll();

		foreach ($allCategories as $obj) {
			if ($this->hasCategoryAccess($user, $obj)) {
				$categoryNames[] = $obj->getName();
			}
		}
		$categoryFilter = (new FilterBasic())->setTarget($table, $column)->setOperator('In');
		foreach ($categoryNames as $name) {
			$categoryFilter->setValue($name);
		}
		return $categoryFilter->toArray();
	}

	/*
	* Returns PowerBI Filters based upon user property and IM Category access
	* @param $reportId (not used)
	* @return Array of <IBasicFilter>
	*/
	public function getPowerBIFilter($reportId): array
	{
		$filters = [];
		$ExbUser = \EXB\User::getCurrent();

		// Source of Gasstation filter per EXB user account
		$userFieldAlias = 'stationtbl';

		// PBI Report specific properties
		$defaultHook = ['query' => 'Alle checks', 'column' => 'incident_category'];
		$stationHook = ['query' => 'Stations', 'column' => 'itemid'];

		$basicFilterPerStation = (new FilterBasic())->setTarget($stationHook['query'], $stationHook['column'])->setOperator('In');

		try {
			// Default EXB Incident management - Access to categories
			//$filters[] = $this->getDefaultFilter($ExbUser,$defaultHook['query'],$defaultHook['column']);

			// Gasstation account alias has 3 digit post fix, other account do not
			if (preg_match('/\d{3}(.*)/', $ExbUser->getDisplayName())) {
				$stationId = $ExbUser->getDocument()->getModel()->getFieldByAlias($userFieldAlias)->getIndex()->getIndexValue()['id'];
				// stationId could be -1, but create filter anyway (expect no registations to be shown in the report)
				$filterPerStation = $basicFilterPerStation->setValue($stationId);
				$filters[] = $filterPerStation->toArray();
			}
		} catch (\Exception $e) {
			\EXB\Kernel::getLogger()->addWarning('Justin made booboo in the powerbi filter (DhmoPcdaWorkflowAccessPlugin)');
			\EXB\Kernel::getLogger()->addException($e);

			$filterOnFailure = $basicFilterPerStation->setValue('-1');
			$filters[] = $filterOnFailure->toArray();
		}
		return $filters;
	}
}
