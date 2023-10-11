<?php

use Bitrix\Main\Event;
use Bitrix\Sale;
use Bitrix\Sale\Delivery\Services\Manager;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use League\OAuth2\Client\Token\AccessTokenInterface;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Models\NoteType\ServiceMessageNote;
use AmoCRM\Filters\ContactsFilter;

$eventManager = \Bitrix\Main\EventManager::getInstance();
$eventManager->addEventHandler('sale', 'OnSaleOrderSaved', 'OnAddOrder');


function OnAddOrder(Event $event)
{
    require_once __DIR__ . '/amocrm/bootstrap.php';


    if (!$event->getParameter('IS_NEW')) {
        return;
    }

    /** @var Sale\Order $order */
    $order = $event->getParameter('ENTITY');
    $basket = $order->getBasket();
    $propertyCollection = $order->getPropertyCollection();

    $products = array();
    $items = $basket->getBasketItems();
    $list = '';
    foreach ($items as $item) {
        $products[] = array(
            'id' => $item->getId(),
            'name' => $item->getField('NAME'),
            'price' => $item->getPrice(),
            'count' => $item->getQuantity(),
        );
        $list .= "Название: {$item->getField('NAME')}; Цена за штуку: {$item->getPrice()}; Количество: {$item->getQuantity()}" . PHP_EOL;
    }
//    $list = null;
//    foreach ($basket->getListOfFormatText() as $item) {
//        $list .= $item . "\r\n";
//    }

    $price = $order->getPrice();
    $discount = $order->getDiscountPrice();
    $description = $order->getField('USER_DESCRIPTION');
    $userName = null;
    $phone = null;
    $email = null;

    foreach ($propertyCollection as $property) {
        $code = $property->getField('CODE');
        $value = $property->getValue();
        // Если в заказе есть какие либо доп. поля, их нужно указать тут.
        switch ($code) {
            case 'PHONE':
                $phone = $value;
                break;
            case 'EMAIL':
                $email = $value;
                break;
            case 'FIO':
                $userName = $value;
                break;
        }
    }

    $paymentCollection = $order->getPaymentCollection();
    $paymentName = $paymentCollection['0']->getPaymentSystemName();
    $deliverySystemId = $order->getDeliverySystemId();
    $managerById = Manager::getById($deliverySystemId['0']);
    $deliveryName = $managerById['NAME'];

    // Следующим образом можно быстро определить не в 1 клик ли заказ
    if (array_key_exists('ONE_CLICK_BUY', $_REQUEST) !== false) {
        $userName = iconv('UTF-8', SITE_CHARSET, $_REQUEST['ONE_CLICK_BUY']['FIO']);
        $phone = $_REQUEST['ONE_CLICK_BUY']['PHONE'];
    }

    $comment = "{$description} \r\n";

    $comment .= "\n\nСписок товаров:\r\n" .
        "{$list}\r\n\r\n" .
        "Способ доставки: {$deliveryName}\r\n" .
        "Способ оплаты: {$paymentName}\r\n";


    $comment .= "Итого - {$price} руб";


    $accessToken = getToken();
    error_reporting(E_ALL);

    $apiClient->setAccessToken($accessToken)
        ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
        ->onAccessTokenRefresh(
            function (AccessTokenInterface $accessToken, string $baseDomain) {
                saveToken(
                    [
                        'accessToken' => $accessToken->getToken(),
                        'refreshToken' => $accessToken->getRefreshToken(),
                        'expires' => $accessToken->getExpires(),
                        'baseDomain' => $baseDomain,
                    ],
                );
            },
        );


    $contact = new ContactModel();
    $contact->setName($userName);
    $customFields = new CustomFieldsValuesCollection();
    $phoneField = (new MultitextCustomFieldValuesModel())->setFieldCode('PHONE');
    //Установим значение поля
    $phoneField->setValues(
        (new MultitextCustomFieldValueCollection())
            ->add(
                (new MultitextCustomFieldValueModel())
                    ->setEnum('WORK')
                    ->setValue($phone),
            ),
    );
    $customFields->add($phoneField);
    $contact->setCustomFieldsValues($customFields);

    $emailField = (new MultitextCustomFieldValuesModel())->setFieldCode('EMAIL');
    //Установим значение поля
    $emailField->setValues(
        (new MultitextCustomFieldValueCollection())
            ->add(
                (new MultitextCustomFieldValueModel())
                    ->setEnum('WORK')
                    ->setValue($email),
            ),
    );
    $customFields->add($emailField);
    $contact->setCustomFieldsValues($customFields);

    try {
        $contactId = $apiClient->contacts()->addOne($contact)->getId();
    } catch (AmoCRMApiException $e) {
        //printError($e);
        //   die;
    }


    usleep(500);
    $leadsService = $apiClient->leads();
//Создадим сделку с заполненным бюджетом и привязанными контактами и компанией
    $lead = new LeadModel();
    $lead->setName("Заказ c profifit.shop № {$order->getId()}")
        ->setStatusId(32727871)
        ->setContacts(
            (new ContactsCollection())
                ->add(
                    (new ContactModel())
                        ->setId($contactId),
                ),
        );

    try {
        $leadsCollection = $leadsService->addOne($lead);
        usleep(500);
//Создадим примечания
        $notesCollection = new NotesCollection();
        $serviceMessageNote = new ServiceMessageNote();
        $serviceMessageNote = new \AmoCRM\Models\NoteType\CommonNote();
        $serviceMessageNote->setEntityId($leadsCollection->getId())
            ->setText($comment)
            //->setService('Описание заказа')
            ->setCreatedBy(0);

        $notesCollection->add($serviceMessageNote);

        $leadNotesService = $apiClient->notes(EntityTypesInterface::LEADS);
        $notesCollection = $leadNotesService->add($notesCollection);
//        var_dump($notesCollection);
    } catch (AmoCRMApiException $e) {
        //printError($e);
        //die;
    }
}



