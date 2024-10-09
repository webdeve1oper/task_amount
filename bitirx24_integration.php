<?php

namespace App\Http\Controllers;

use App\Models\Notebook;
use App\Models\Timeline;
use DateTime;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CrmController extends Controller
{
    protected $client;
    protected $webhookUrl = '';
    public $type_id = 156;
    public $deal_category = 26; // 19 физ лицо

    public function __construct()
    {
        $this->client = new Client();

    }
    public function log(Request $request){
        $response = $this->client ->request('GET', $this->webhookUrl.'crm.item.get', [
            'query' => [
                'entityTypeId'=> 156,
                'id'=> 16,
                'SELECT'=> [ "ID", "TITLE", "STAGE_ID", "COMPANY_ID", "CONTACT_ID",
                    "UF_*", // АДРЕС ДОСТАВКИ
                ]
            ]
        ]);
        $body = json_decode($response->getBody(), true);
        dd($body);
    }

    public function downloadImage($id = 1, $url = '')
    {
        if($url){
            $response = Http::get($url);
            if ($response->successful()) {
                $contentType = $response->header('Content-Type');
                $mimeTypes = [
                    'image/jpeg' => '.jpg',
                    'image/png' => '.png',
                    'application/pdf' => '.pdf',
                ];
                $extension = $mimeTypes[$contentType] ?? '';

                // Сохранение файла
                $filename = time() . '_'.$id . $extension;
                $file_path = '/images/' . $filename;
                Storage::disk('local')->put('public'.$file_path, $response->body());
                return $file_path;
            }
        }
        return null;
    }

    public function getDate($date = null){
        if($date){
            $date = new DateTime($date);
            return $date->format('Y-m-d');
        }
        return null;
    }

    public function legalEntities(){
        $start = 0;
        $entities = [];
        do {
            $response = $this->client->request('GET', $this->webhookUrl.'/crm.item.list', [
                'query'=>[
                    'entityTypeId'=>$this->type_id,
                    'SELECT' => ["id", "title", "stageId", "companyId", "parentId2", "contactId", 'ufCrm*'],
                ]
            ]);

            if ($response->getStatusCode() == 200) {
                $body = json_decode($response->getBody(), true);
                $entities = array_merge($entities, $body['result']['items']);
                $start = array_key_exists('next', $body) ? $body['next'] : null;
            } else {
                break;
            }
        } while ($start);
        $notebooks = [];
        foreach ($entities as &$deal) { // фонд техники
            if (!$deal['parentId2']){
                continue;
            }
            $notebook = Notebook::where('crm_id', $deal['id'])->whereNotNull('parent_id')->first();
            if (!$notebook) {
                $notebook = new Notebook();
            }
            $notebook->crm_id = $deal['id'];
            $notebook->status = $deal['stageId'];
            $notebook->notebook_id = $deal['ufCrm46_1701076684'];
            $notebook->model = $deal['title'];
            $notebook->parent_id = $deal['parentId2'];
            $notebook->date_receiving = $this->getDate($deal['ufCrm_46_STAGE1']); // прием
            $notebook->date_on_repair = $this->getDate($deal['ufCrm_46_STAGE2REMONT']); // ремонт
            $notebook->date_on_configuration = $this->getDate($deal['ufCrm46_1701162620']); // комплектации
            $notebook->date_on_distribution = $this->getDate($deal['ufCrm_46_STAGE4NARASPR']); // распределение
            $notebook->date_on_sending = $this->getDate($deal['ufCrm_46_STAGE5VPUTI']); // отправка
            $notebook->date_delivery = $this->getDate($deal['ufCrm_46_STAGE6_DOSTAVILI_USPESHNO']); // доставлен
            $notebook->date_handed_over = $this->getDate($deal['ufCrm_46_STAGE7VRUCHILI']); // вручили
            if ($deal['ufCrm46_1701079723']){ // область
                $notebook->region = explode('|', $deal['ufCrm46_1701079723'])[0];
            }
            if ($deal['ufCrm46_1701079734']){ // Номер школы
                $notebook->school = explode('|', $deal['ufCrm46_1701079734'])[0];
            }
            if($deal['ufCrm46_1701079686']){// получатель
                if (strpos(trim($deal['ufCrm46_1701079686']), ' ') !== false) {
                    $parts = explode(' ', $deal['ufCrm46_1701079686']);
                    $surname = $parts[0];
                    $nameInitial = mb_substr($parts[1], 0, 1) . '.';
                    $patronymicInitial = isset($parts[2]) ? mb_substr($parts[2], 0, 1) . '.' : '';
                    $notebook->recipient = $surname . ' ' . $nameInitial . ' ' . $patronymicInitial;
                }
            }
            if($deal['ufCrm46_1701079745']){
                $notebook->notebook_image = $this->downloadImage($notebook->crm_id, $deal['ufCrm46_1701079745']['urlMachine']);
            }
            if($deal['ufCrm46_1701146216098']){
                $images = [];
                foreach ($deal['ufCrm46_1701146216098'] as $item) {
                    $images[] = $this->downloadImage($notebook->crm_id, $item['urlMachine']);
                }
                $notebook->configuration_image = implode(',', $images);
            }
            if($deal['ufCrm46PhotoSucces']){
                $notebook->handed_over_image = $this->downloadImage($notebook->crm_id, $deal['ufCrm46PhotoSucces']['urlMachine']);
            }
            $notebook->save();
            $notebooks[] = $notebook;
        }

        $legal_entities_ids = array_unique(array_column($entities, 'parentId2'));
        $legal_entities_ids = array_filter($legal_entities_ids, fn($n) => !is_null($n));

        $allDeals = [];
        $start = 0;
        do {
            $response = $this->client->get($this->webhookUrl . 'crm.deal.list', [
                'query' => [
                    'filter' => ['@ID' => $legal_entities_ids],
                    'SELECT' => ["ID", "TITLE", 'COMPANY_ID', 'CONTACT_ID'],
                ]
            ]);
            if ($response->getStatusCode() == 200) {
                $body = json_decode($response->getBody(), true);
                $allDeals = array_merge($allDeals, $body['result']);
                $start = array_key_exists('next', $body) ? $body['next'] : null;
            } else {
                break;
            }
        } while ($start);

        foreach ($allDeals as &$deal) { // все родительские сделки
            $notebook = Notebook::where('crm_id', $deal['ID'])->where('parent_id', null)->first();
            if (!$notebook) {
                $notebook = new Notebook();
            }
            $notebook->crm_id = $deal['ID'];
            $notebook->notebook_id = $deal['TITLE'];
            if ($deal['CONTACT_ID'] and $deal['COMPANY_ID'] == 0) { // Меценат
                $contact_data = $this->getContact($deal['CONTACT_ID']);
                if (strpos(trim($contact_data['LAST_NAME']), ' ') !== false) {
                    $parts = explode(' ', $contact_data['LAST_NAME']);
                    $surname = $parts[0];
                    $nameInitial = mb_substr($parts[1], 0, 1) . '.';
                    $patronymicInitial = isset($parts[2]) ? mb_substr($parts[2], 0, 1) . '.' : '';
                    $notebook->maecenas = $surname . ' ' . $nameInitial . ' ' . $patronymicInitial;
                }else{
                    $notebook->full_name = $contact_data['LAST_NAME'] . ' ' . $contact_data['NAME'];
                    $notebook->maecenas = $contact_data['NAME'];
                    if($contact_data['LAST_NAME'] != ''){
                        $notebook->maecenas = $contact_data['LAST_NAME'] . ' ' . mb_substr($contact_data['NAME'], 0, 1) . '.';
                    }
                }
            } elseif ($deal['COMPANY_ID'] != 0) {
                $contact_data = $this->getCompany($deal['COMPANY_ID']);
                $notebook->company = $contact_data['TITLE'];
            }
            $notebook->save();
        }
        return Log::alert('Deals loaded');
    }

    public function getContact($id)
    {
        $contact_d = $id;
        $client = new Client();
        $response = $client->request('GET', $this->webhookUrl . 'crm.contact.get', [
            'query' => [
                'id' => $contact_d
            ]
        ]);

        if ($response->getStatusCode() == 200) {
            $contactData = json_decode($response->getBody(), true);
            return $contactData['result'];
        }
        return null;
    }

    public function getCompany($id)
    {
        $company_id = $id;
        $client = new Client();
        $response = $client->request('GET', $this->webhookUrl . 'crm.company.get', [
            'query' => [
                'id' => $company_id
            ]
        ]);
        if ($response->getStatusCode() == 200) {
            $contactData = json_decode($response->getBody(), true);
            return $contactData['result'];
        }
        return null;
    }

    public function createContact($contactDetails)
    {
        $response = $this->client->post("{$this->webhookUrl}/crm.contact.add", [
            'json' => ['fields' => $contactDetails]
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function volunteerToCrm(Request $request)
    {
        $validator = validator::make($request->all(), [
            'NAME' => 'required|max:255|min:2',
            'PHONE' => 'required|min:10', // example phone validation
        ], [
            'NAME.required' => 'Пожалуйста, введите ваше имя.',
            'NAME.max' => 'Имя не может быть более 255 символов.',
            'NAME.min' => 'Имя не может быть менее 2 символов.',
            'PHONE.required' => 'Пожалуйста, введите номер телефона.',
            'PHONE.min' => 'Номер телефона должен содержать минимум 10 цифр.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->getMessages())->setStatusCode(500);
        }

        $data = $request->all();
        $data['TITLE'] = 'Стать волонтёром';
        $data['COMMENTS'] = implode(',', $data['COMMENTS']);
        $data['PHONE'] = [['VALUE'=>$data['PHONE'], 'VALUE_TYPE'=>'WORK']];
        unset($data['_token']);
        if ($request->ajax() or $request->method() == 'POST'){
            if(isset($request->COMPANY) && !empty($request->COMPANY)){
                $companyResponse = $this->client->post("{$this->webhookUrl}/crm.company.add", [
                    'json' => ['fields' => ['TITLE'=>$request->COMPANY]]
                ]);
                $companyData = json_decode($companyResponse->getBody()->getContents(), true);
                $companyId = $companyData['result'] ?? null;
                if ($companyId) {
                    $data['COMPANY_ID'] = $companyId;
                }
            }
            $contactData = $this->createContact($data);

            if (isset($contactData['result'])) {
                $contactId = $contactData['result'];

                // Use the contact ID in the deal details
                $dealDetails['CONTACT_ID'] = $contactId;
                $dealDetails['CATEGORY_ID'] = 28;
                // Now, create the deal
                $dealResponse = $this->client->post("{$this->webhookUrl}/crm.deal.add", [
                    'json' => ['fields' => $dealDetails]
                ]);

                return json_decode($dealResponse->getBody()->getContents(), true);
            }

            return null;
        }
    }


    public function sendToCrm(Request $request){
        $validator = validator::make($request->all(), [
            'NAME' => 'required|max:255|min:2',
            'PHONE' => 'required|min:10', // example phone validation
        ], [
            'NAME.required' => 'Пожалуйста, введите ваше имя.',
            'NAME.max' => 'Имя не может быть более 255 символов.',
            'NAME.min' => 'Имя не может быть менее 2 символов.',
            'PHONE.required' => 'Пожалуйста, введите номер телефона.',
            'PHONE.min' => 'Номер телефона должен содержать минимум 10 цифр.',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->getMessages())->setStatusCode(500);
        }
        $data = $request->all();
        $data['PHONE'] = [['VALUE'=>$data['PHONE'], 'VALUE_TYPE'=>'WORK']];
        unset($data['_token']);
        $companyId = null;
        if ($request->ajax() or $request->method() == 'POST'){
            $notebook = new Notebook();
            if(isset($request->COMPANY) && !empty($request->COMPANY)){
                $companyResponse = $this->client->post("{$this->webhookUrl}/crm.company.add", [
                    'json' => ['fields' => ['TITLE'=>$request->COMPANY]]
                ]);
                $companyData = json_decode($companyResponse->getBody()->getContents(), true);
                $companyId = $companyData['result'] ?? null;
                if ($companyId) {
                    $data['COMPANY_ID'] = $companyId;
                }
                $notebook->company = $request->COMPANY;
            }
            $contactData = $this->createContact($data);
            $notebook->maecenas = $request->NAME;
            if (isset($contactData['result'])) {
                $contactId = $contactData['result'];

                // Use the contact ID in the deal details
                $dealDetails['CONTACT_ID'] = $contactId;
                $dealDetails['CATEGORY_ID'] = $this->deal_category;
                if ($companyId) {
                    $dealDetails['COMPANY_ID'] = $companyId;
                }
                do {
                    $token = Str::random(100);
                } while (Notebook::where('contact_token', $token)->exists());
                $notebook->contact_token = $token;
                $dealDetails['UF_CRM_LINK_WATSUP'] = 'https://'.request()->httpHost().'/'.getLang().'/deal/'.$token;
                $notebook->save();
                $dealResponse = $this->client->post("{$this->webhookUrl}/crm.deal.add", [
                    'json' => ['fields' => $dealDetails]
                ]);


                return json_decode($dealResponse->getBody()->getContents(), true);
            }

            return null;
        }
    }

    public function feedback(Request $request){
        $data = $request->all();
        unset($data['_token']);
        $dealResponse = $this->client->post("{$this->webhookUrl}/crm.deal.update", [
            'json' => $data
        ]);
        if ($dealResponse->getStatusCode() == 200) {
            return true;
        }
        return false;
    }
}
