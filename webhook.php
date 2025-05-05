<?php
declare(strict_types=1);

ini_set('error_reporting', 'E_ALL');
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/AmoClient.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawData = file_get_contents('php://input');
    file_put_contents(WEBHOOK_LOG_FILE, $rawData . PHP_EOL . PHP_EOL, FILE_APPEND);
    parse_str($rawData, $webhookData);

    if (!$webhookData) {
        http_response_code(400);
        file_put_contents('logs/error.log', $rawData . PHP_EOL . PHP_EOL, FILE_APPEND);
        exit;
    }

    $amoClient = new AmoClient(AMOCRM_DOMAIN, CLIENT_ID, CLIENT_SECRET, AUTH_CODE, REDIRECT_URI);

    $eventMap = [
        'add' => ['contacts' => 'Контакт', 'leads' => 'Сделка'],
        'update' => ['contacts' => 'Контакт', 'leads' => 'Сделка']
    ];

    foreach ($eventMap as $action => $entities) {
        foreach ($entities as $entityKey => $entityLabel) {
            if (!empty($webhookData[$entityKey][$action])) {
                foreach ($webhookData[$entityKey][$action] as $item) {
                    $id = $item['id'] ?? null;
                    if (!$id) continue;

                    $time = date('Y-m-d H:i:s');
                    $noteText = '';

                    if ($action === 'add') {
                        $name = $item['name'] ?? ($entityKey === 'leads'
                            ? 'Без названия'
                            : 'Без имени');
                        $responsible = $item['responsible_user_id'] ?? 'Неизвестно';
                        $noteText = "$entityLabel добавлен. Название: $name. Ответственный: $responsible. Время: $time.";
                    }

                    if ($action === 'update') {
                        $changes = [];

                        // Обрабатываем стандартные поля
                        $standardFields = ['name', 'responsible_user_id', 'price', 'status_id'];
                        foreach ($standardFields as $field) {
                            if (isset($item[$field])) {
                                $changes[] = "$field: {$item[$field]}";
                            }
                        }

                        // Обрабатываем кастомные поля
                        if (!empty($item['custom_fields_values'])) {
                            foreach ($item['custom_fields_values'] as $field) {
                                $fieldName = $field['field_name'] ?? ($field['field_code'] ?? 'Неизвестное поле');
                                $values = [];
                                foreach ($field['values'] as $val) {
                                    $values[] = $val['value'];
                                }
                                $changes[] = "$fieldName: " . implode(', ', $values);
                            }
                        }

                        if ($changes) {
                            $noteText = "$entityLabel обновлён. Изменения: " . implode('; ', $changes) . ". Время: $time.";
                        }
                    }

                    if ($noteText) {
                        try {
                            $amoClient->addNote((int)$id, $entityKey, $noteText);
                        } catch (Exception $e) {
                            file_put_contents('logs/error.log', "Note error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                        }
                    }
                }
            }
        }
    }

    http_response_code(200);
    echo 'Webhook обработан';
}
