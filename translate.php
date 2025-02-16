<?php

    /**
     * 
     * НАСТРОЙКИ
     * 
     **/

// ====== Настройка CORS ======
//header('Access-Control-Allow-Origin: *'); // Разрешить доступ с любых доменов (при необходимости расскоментировать, если будет ругаться на CORS)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
    
// Модели
//Какую модель использовать просто закомментируйте ненужную и расскоментируйте нужную модель
// Пояснение gpt3.5-turbo - самая быстрая и дешевая модель, но чаще ошибается чем gpt4 - он умнее и точнее понимает контекст и дает более качественный перевод, но медленее (рекомандуется для качетсвенного перевода)
// gpt3.5 - хуже справляется с языками третьих стран, бангладеш, арабские и т.д.
// В целом перевод на самые используемые вами языки будет стоить около 1$, ну или бесплатно если читали предпоследний пост) https://t.me/bearded_cpa/847

$model = 'gpt-3.5-turbo';
//$model = 'gpt-4';
//$model = 'gpt-4-turbo';
//$model = 'gpt-4o-mini';

// Ключ API от OpenAI – замените на свой реальный ключ
$OPENAI_API_KEY = 'sk-СЮДА-КЛЮЧ-ОТ-АПИ-OPEN-AI';

    /**
     * 
     * НАСТРОЙКИ - END
     * 
     **/


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Получаем входной JSON
$rawInput = file_get_contents('php://input');
$req = json_decode($rawInput, true) ?? [];
$sourceLang = $req['sourceLanguage'] ?? '';
$targetLang = $req['targetLanguage'] ?? '';
$lines = $req['lines'] ?? [];

// Дополнительные параметры для накопления полного перевода
$cacheKey = $req['cacheKey'] ?? null; // например, "ru"
$totalLines = isset($req['totalLines']) ? intval($req['totalLines']) : null; // общее число строк лендинга

if (empty($sourceLang) || empty($targetLang) || !is_array($lines)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request data']);
    exit;
}

// Определяем папку кэша и имя файла для перевода
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
// Если cacheKey задан, используем его (например, "ru.json"), иначе используем targetLang.json
if ($cacheKey) {
    $cacheFile = "$cacheDir/$cacheKey.json";
} else {
    $cacheFile = "$cacheDir/$targetLang.json";
}

// Если кэш существует и (при наличии totalLines) число строк равно полному количеству,
// то возвращаем кэш сразу
if (file_exists($cacheFile) && $totalLines !== null) {
    $cachedData = json_decode(file_get_contents($cacheFile), true) ?? [];
    if (!empty($cachedData) && isset($cachedData['lines']) && count($cachedData['lines']) === $totalLines) {
        echo json_encode($cachedData);
        exit;
    }
}


/**
 * Функция для перевода одного чанка с повторными попытками.
 * Требуется, чтобы модель вернула ровно ожидаемое число элементов.
 */
function translateChunk($chunkLines, $sourceLang, $targetLang, $model, $OPENAI_API_KEY) {
  
    // сколько делать попыток при неудачном переводе
    $maxAttempts = 3;
    $attempt = 0;
    $expectedCount = count($chunkLines);
    $translated = null;
    
    while ($attempt < $maxAttempts) {
        // Формируем промпт с требованием не объединять строки
        $prompt = "
Translate this JSON array from $sourceLang to $targetLang.
Keep the same structure and IDs.
Return ONLY the JSON without any additional text or explanations.
Ensure that the output is valid JSON.
Do NOT combine or omit any items.
The output must contain exactly $expectedCount objects in the 'lines' array.
" . json_encode(['lines' => $chunkLines], JSON_UNESCAPED_UNICODE);
        
        $postData = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a professional translator. Preserve the JSON structure. Translate only the "text" values and do not change the "id".'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.0
        ];
        
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TCP_KEEPALIVE => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $OPENAI_API_KEY
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_RETURNTRANSFER => true
        ]);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $errorMessage = 'API error: ' . curl_error($ch);
            curl_close($ch);
            throw new Exception($errorMessage);
        }
        curl_close($ch);
        
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            throw new Exception('OpenAI error: ' . $data['error']['message']);
        }
        
        $gptResponse = $data['choices'][0]['message']['content'] ?? '';
        // Для отладки можно добавить: error_log("GPT raw response: " . $gptResponse);
        preg_match('/\{.*\}/s', $gptResponse, $matches);
        if (!empty($matches[0])) {
            $translated = json_decode($matches[0], true);
        } else {
            throw new Exception('Failed to extract JSON from response');
        }
        
        if (!isset($translated['lines']) || !is_array($translated['lines'])) {
            throw new Exception('Invalid translation format: no lines found');
        }
        $translatedCount = count($translated['lines']);
        if ($translatedCount === $expectedCount) {
            return $translated['lines'];
        } else {
            $attempt++;
            error_log("Attempt $attempt: Expected $expectedCount lines, got $translatedCount. Retrying...");
            sleep(1);
        }
    }
    throw new Exception('Invalid translation format in chunk: expected ' . $expectedCount . ' lines, got ' . (isset($translated['lines']) ? count($translated['lines']) : 0));
}

// Получаем перевод для данного чанка (клиент отправляет один чанк за раз)
try {
    $translatedChunk = translateChunk($lines, $sourceLang, $targetLang, $model, $OPENAI_API_KEY);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Если cacheKey задан, объединяем новый переведённый чанк с ранее сохранёнными (если они есть)
if ($cacheKey) {
    $merged = [];
    // Если файл кэша существует, загружаем ранее сохранённые строки и индексируем их по id
    if (file_exists($cacheFile)) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if (!empty($cachedData) && isset($cachedData['lines'])) {
            foreach ($cachedData['lines'] as $line) {
                $merged[(string)$line['id']] = $line;
            }
        }
    }
    // Объединяем: обновляем или добавляем строки из текущего чанка
    foreach ($translatedChunk as $line) {
        $merged[(string)$line['id']] = $line;
    }
    // Приводим ассоциативный массив к индексированному
    $mergedTranslation = array_values($merged);
    // Сохраняем объединённый перевод в кэш (например, ru.json)
    file_put_contents($cacheFile, json_encode(['lines' => $mergedTranslation], JSON_UNESCAPED_UNICODE));
    
    // Если totalLines задан и объединённый перевод полный, возвращаем его
    if ($totalLines !== null && count($mergedTranslation) === $totalLines) {
        echo json_encode(['lines' => $mergedTranslation]);
        exit;
    }
    // Иначе возвращаем частичный объединённый перевод (клиент будет отправлять оставшиеся чанки)
    echo json_encode(['lines' => $mergedTranslation]);
} else {
    // Если cacheKey не задан, просто возвращаем перевод данного чанка
    echo json_encode(['lines' => $translatedChunk]);
}
