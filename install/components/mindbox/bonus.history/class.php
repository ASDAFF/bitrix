<?php
/**
 * Created by @copyright QSOFT.
 */

use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Mindbox\Ajax;
use Mindbox\DTO\V3\Requests\CustomerRequestDTO;
use Mindbox\DTO\V3\Requests\PageRequestDTO;
use Mindbox\Exceptions\MindboxException;
use Mindbox\Helper;
use Mindbox\Options;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    die();
}

class BonusHistory extends CBitrixComponent implements Controllerable
{
    protected $actions = [
        'page'
    ];

    private $mindbox;

    public function __construct(CBitrixComponent $component = null)
    {
        parent::__construct($component);

        try {
            if(!Loader::includeModule('qsoftm.mindbox')) {
                ShowError(GetMessage('MB_BH_MODULE_NOT_INCLUDED', ['#MODULE#' => 'qsoftm.mindbox']));
                return;
            }
        } catch (LoaderException $e) {
            ShowError(GetMessage('MB_BH_MODULE_NOT_INCLUDED', ['#MODULE#' => 'qsoftm.mindbox']));;
            return;
        }

        $this->mindbox = Options::getConfig();
    }

    public function configureActions()
    {
        return Ajax::configureActions($this->actions);
    }

    public function pageAction($page)
    {
        $page = intval($page);
        $this->arParams = Ajax::loadParams(self::getName());
        $size = isset($this->arParams['PAGE_SIZE']) ? $this->arParams['PAGE_SIZE'] : 0;

        try {
            $orders = $this->getHistory($page);
            $showMore = count($orders) === intval($size);

            return [
                'type' => 'success',
                'page' => $page,
                'html' => $this->getHtml($orders),
                'more' => $showMore
            ];
        } catch (Mindbox\Exceptions\MindboxException $e) {
            return Ajax::errorResponse('Can\'t load requested page');
        }
    }


    /**
     * @param $page
     * @return array
     * @throws  MindboxException
     */
    public function getHistory($page)
    {
        if (!$this->mindbox) {
            return Ajax::errorResponse(GetMessage('MB_BH_BAD_MODULE_SETTING'));
        }
        $page = intval($page);
        $history = [];
        $mindboxId = $this->getMindboxId();
        $operation = Options::getOperationName('getBonusPointsHistory');

        $pageDTO = new PageRequestDTO();
        $pageDTO->setItemsPerPage($this->arParams['PAGE_SIZE']);
        $pageDTO->setPageNumber($page);

        $customer = new CustomerRequestDTO();
        $customer->setId('mindboxId', $mindboxId);

        try {
            $response = $this->mindbox->customer()->getBonusPointsHistory($customer, $pageDTO,
                $operation)->sendRequest();
        } catch (Exception $e) {
            throw new MindboxException('Requested page is empty or doesn\'t exist');
        }

        $result = $response->getResult();

        if(!$result->getCustomerActions()) {
            throw new MindboxException('Requested page is empty or doesn\'t exist');
        }

        foreach ($result->getCustomerActions() as $action) {
            $history[] = [
                'start' => $this->formatTime($action->getDateTimeUtc()),
                'size' => $action->getCustomerBalanceChanges()[0]->getChangeAmount(),
                'name' => $action->getActionTemplate()->getName(),
                'end' => $this->formatTime($action->getCustomerBalanceChanges()[0]->getExpirationDateTimeUtc())
            ];
        }

        foreach ($result->getBalances() as $balance) {
            if($balance->getField('systemName') === 'Main') {
                $this->arResult['BALANCE'] = [
                    'available' => $balance->getField('available'),
                    'blocked' => $balance->getField('blocked')
                ];
            }
        }

        return $history;
    }

    public function formatTime($utc)
    {
        return str_replace('T', ' ', substr($utc, 0, -1));
    }

    public function executeComponent()
    {
        parent::executeComponent();

        $_SESSION[self::getName()] = $this->arParams;

        $this->prepareResult();

        $this->includeComponentTemplate();
    }

    public function prepareResult()
    {
        $page = 1;

        try {
            $this->arResult['HISTORY'] = $this->getHistory($page);
        } catch (MindboxException $e) {
            $this->arResult['ERROR'] = GetMessage('MB_BH_ERROR_MESSAGE');
        }
    }

    protected function getHtml($history)
    {
        $html = '';

        foreach ($history as $change) {
            $html .= GetMessage('MB_BH_BALANCE_HTML',
                [
                   '#START#' => $change['start'],
                   '#SIZE#' => $change['size'],
                   '#END#' => $change['end'],
                   '#NAME#' => $change['name']
                ]
            );
        }

        return $html;
    }

    private function getMindboxId()
    {
        global $USER;

        return Helper::getMindboxId($USER->GetID());
    }
}