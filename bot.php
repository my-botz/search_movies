<?php

$botToken = "6771999599:AAEO2ny0ns92tBI48JcBlbmUa6USwvVNSik"; 
$channelId = "-1002354417398";
$adminId = "7415337843"; // המזהה של המנהל

$apiUrl = "https://api.telegram.org/bot$botToken/";
$files = [];
$resultsPerPage = 10;
$startFromId = 1;

function sendRequest($method, $parameters) {
    global $apiUrl;
    $url = $apiUrl . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($parameters),
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return json_decode($result, true);
}

function saveFile($fileId, $messageId, $fileName) {
    // מציאת הקובץ האחרון
    $fileIndex = 1;
    while (file_exists("files$fileIndex.json")) {
        $fileIndex++;
    }
    $fileIndex = max(1, $fileIndex - 1);
    $currentFilePath = "files$fileIndex.json";

    // טעינת הקבצים הקיימים
    $existingFiles = file_exists($currentFilePath) 
        ? json_decode(file_get_contents($currentFilePath), true) 
        : [];

    // בדיקה אם יש צורך ביצירת קובץ חדש
    if (count($existingFiles) >= 70000) {
        $fileIndex++;
        $currentFilePath = "files$fileIndex.json";
        $existingFiles = [];
    }

    // בדיקה שהקובץ לא כבר קיים
    foreach ($existingFiles as $data) {
        if ($data['file_id'] == $fileId) {
            return;
        }
    }

    // חישוב המזהה הייחודי
    $uniqueId = $startFromId + count($existingFiles);
    $fileName = str_replace(['_', '-', '.'], ' ', $fileName);

    // הוספת הקובץ לרשימה
    $existingFiles[$uniqueId] = [
        'message_id' => $messageId, 
        'file_id' => $fileId, 
        'file_name' => $fileName
    ];

    // שמירת הקובץ
    file_put_contents($currentFilePath, json_encode($existingFiles));
}

function loadFiles() {
    global $files;
    
    // איפוס המערך הכללי
    $files = [];

    // טעינת כל קבצי files*.json
    $fileIndex = 1;
    while (file_exists("files$fileIndex.json")) {
        $currentFiles = json_decode(file_get_contents("files$fileIndex.json"), true);
        
        // הוספת הקבצים לאוסף הכללי
        if (!empty($currentFiles)) {
            $files = array_merge($files, $currentFiles);
        }
        
        $fileIndex++;
    }
}

function cleanFileName($fileName) {
    // רשימת הביטויים להסרה
    $removePatterns = ['mp4', 'mkv', 'avi',
    ];
    
    // הסרת כל אחד מהביטויים
    foreach ($removePatterns as $pattern) {
        $fileName = preg_replace('/\b' . preg_quote($pattern, '/') . '\b/ui', '', $fileName);
    }
    
    // הסרת רווחים כפולים שנוצרו
    $fileName = preg_replace('/\s+/', ' ', $fileName);
    
    // הסרת רווחים מתחילת וסוף השם
    return trim($fileName);
}

function extractSeasonEpisode($fileName) {
    $season = 0;
    $episode = 0;
    
    // חיפוש עונה ופרק בפורמטים שונים
    if (preg_match('/(?:עונה|season|ע|s)\s*(\d+).*?(?:פרק|episode|פ|e)\s*(\d+)/i', $fileName, $matches)) {
        $season = intval($matches[1]);
        $episode = intval($matches[2]);
    } elseif (preg_match('/(?:עונה|season|ע|s)\s*(\d+)/i', $fileName, $matches)) {
        $season = intval($matches[1]);
    } elseif (preg_match('/(?:פרק|episode|פ|e)\s*(\d+)/i', $fileName, $matches)) {
        $episode = intval($matches[1]);
    }
    
    return ['season' => $season, 'episode' => $episode];
}

function searchFiles($query) {
    global $files;
    $results = [];
    
    foreach ($files as $uniqueId => $data) {
        if (stripos($data['file_name'], $query) !== false) {
            $seasonEpisode = extractSeasonEpisode($data['file_name']);
            $cleanName = cleanFileName($data['file_name']);
            $results[] = [
                'id' => $uniqueId, 
                'name' => $cleanName, 
                'message_id' => $data['message_id'],
                'season' => $seasonEpisode['season'],
                'episode' => $seasonEpisode['episode']
            ];
        }
    }

    // מיון התוצאות לפי עונה ופרק
    usort($results, function($a, $b) {
        if ($a['season'] != $b['season']) {
            return $a['season'] - $b['season'];
        }
        return $a['episode'] - $b['episode'];
    });

    return $results;
}

function forwardFileToUser($chatId, $messageId) {
    global $channelId;
    sendRequest('copyMessage', [
        'chat_id' => $chatId,
        'from_chat_id' => $channelId,
        'message_id' => $messageId
    ]);
}

function paginateResults($results, $page = 1) {
    global $resultsPerPage;
    $totalPages = ceil(count($results) / $resultsPerPage);
    $startIndex = ($page - 1) * $resultsPerPage;
    return [array_slice($results, $startIndex, $resultsPerPage), $totalPages];
}

// טעינת הקבצים בהתחלה
loadFiles();

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $chatType = $message['chat']['type'] ?? 'private';

    // ספירת משתמשים
    $userList = file_get_contents("users.txt");
    $userIds = explode("\n", $userList);
    if (!in_array($chatId, $userIds) && $chatType === 'private') {
        file_put_contents("users.txt", $chatId . "\n", FILE_APPEND);
    }

    if ($message['chat']['id'] == $channelId) {
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
            $messageId = $message['message_id'];
            $fileName = $message['document']['file_name'];
            saveFile($fileId, $messageId, $fileName);
        } elseif (isset($message['video'])) {
            $fileId = $message['video']['file_id'];
            $messageId = $message['message_id'];
            $fileName = isset($message['video']['file_name']) ? $message['video']['file_name'] : "וידאו ללא שם";
            saveFile($fileId, $messageId, $fileName);
        }
    }

    if (strpos($text, '/start') === 0) {
        $uniqueId = substr($text, 7);
        if (strlen($uniqueId) > 0) {
            $uniqueId = intval($uniqueId);
            if (isset($files[$uniqueId])) {
                forwardFileToUser($chatId, $files[$uniqueId]['message_id']);
            } elseif (isset($newFiles[$uniqueId])) {
                forwardFileToUser($chatId, $newFiles[$uniqueId]['message_id']);
            }
        } else {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'למעבר לקבוצה של הבוט',
                'url' => 'https://t.me/bot_Search_movies']],
            [['text' =>  'מדריך לחיפוש בבוט',
            'callback_data' => 'מדריך'],
        ['text' => 'הודעה לזכויות יוצרים',
            'callback_data' => 'זכויות יוצרים']],
            [['text' => 'לערוץ העדכונים של הבוט',
            'url' => 'https://t.me/amoviesnww1o']],
        ]
    ];

    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "<b>היי👋
אני בוט עם מאגר ענק של סרטים וסדרות😎

כדי לחפש סרט/סדרה פשוט שלחו את השם שלו בקבוצה של הבוט.

👨🏼‍💻מתכנת ראשי: @BOSS1480</b>",
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true, 
        'reply_markup' => json_encode($keyboard),
        'reply_to_message_id' => $message['message_id']
    ]);
}


    } elseif (!empty($text)) {
        if ($chatType === 'private') {
            // שינוי כאן - הפקודה /פאנל תעבוד גם בפרטי
            if (strpos($text, '/פאנל') === 0 && $message['from']['id'] == $adminId) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "ברוך הבא מנהל👨‍⚕️",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '📊משתמשים📊', 'callback_data' => "user"]],
                            [['text' => '✏️הודעה למשתמשים✏️', 'callback_data' => "message"]]
                        ]
                    ])
                ]);
                return; // יציאה מהפונקציה לאחר שליחת הפאנל
            }
   sendRequest('sendMessage', [
    'chat_id' => $chatId,
    'text' => "<b>הבוט לא עובד בפרטי, אלא רק בקבוצות😔</b>",
    'parse_mode' => 'HTML',
    'reply_markup' => json_encode([
        'inline_keyboard' => [[
            [
                'text' => '👈למעבר לקבוצה של הבוט👉',
                'url' => 'https://t.me/bot_Search_movies'
            ]
        ]]
    ]),
    'disable_web_page_preview' => true, 
    'reply_to_message_id' => $message['message_id']
]);
           

            $deleteParams = [
                'chat_id' => $chatId,
                'message_id' => $response['message_id']
            ];
            
            file_get_contents($apiUrl . "deleteMessage?" . http_build_query($deleteParams), false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query($deleteParams),
                    'timeout' => 0.5
                ]
            ]));

            return;
        }

        $searchQuery = $text;
        if (strpos($text, '!') === 0) {
            $searchQuery = trim(substr($text, 1));
        }
        
        $results = searchFiles($searchQuery);
        $page = 1;

        if (!empty($results)) {
            list($pagedResults, $totalPages) = paginateResults($results, $page);

            // שמור את הנתונים של הדף הנוכחי
            $inlineKeyboard = [];
            foreach ($pagedResults as $result) {
                $buttonText = (strlen($result['name']) > 60) ? mb_substr($result['name'], 0, 60) . '' : $result['name'];
                $inlineKeyboard[] = [
                    ['text' => $buttonText, 'url' => "https://t.me/filter_sratim3_bot?start=" . $result['id']]
                ];
            }

            // כפתורי ניווט
            $navigationButtons = [];
            if ($page > 1) {
                $navigationButtons[] = ['text' => '<--', 'callback_data' => $searchQuery . "_" . ($page - 1)];
            }
            if ($page < $totalPages) {
                $navigationButtons[] = ['text' => '-->', 'callback_data' => $searchQuery . "_" . ($page + 1)];
            }

            if (!empty($navigationButtons)) {
                $inlineKeyboard[] = $navigationButtons;
            }

            // שליחת התוצאות
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "התוצאות שנמצאו עבור <b>'$searchQuery'</b>\n (דף $page מתוך $totalPages)",
                'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
                'reply_to_message_id' => $message['message_id'],
                'parse_mode' => 'HTML'
            ]);
        } else {
            $response = sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "לא נמצאו תוצאות עבור <b>'$searchQuery'</b>🫤",
                'reply_to_message_id' => $message['message_id'],
                'parse_mode' => 'HTML'
            ]);
            
            if (isset($response['message_id'])) {
                $deleteParams = [
                    'chat_id' => $chatId,
                    'message_id' => $response['message_id']
                ];
                
                file_get_contents($apiUrl . "deleteMessage?" . http_build_query($deleteParams), false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/x-www-form-urlencoded',
                        'content' => http_build_query($deleteParams),
                        'timeout' => 0.5
                    ]
                ]));
            }
        }
    }
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $chatId2 = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $callbackData = $callbackQuery['data'];
    $id2 = $callbackQuery['from']['id'];

    // פאנל ניהול
    if ($id2 == $adminId) {
        if ($callbackData == "user") {
            $user = file_get_contents("users.txt");
            $member_id = explode("\n", $user);
            $member_count = count($member_id) - 1;
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "סה'כ משתמשים : *$member_count*",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => 'חזרה↩️', 'callback_data' => 'home']]
                    ]
                ])
            ]);
        } elseif ($callbackData == "home") {
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "ברוך הבא מנהל👨‍⚕️",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📊משתמשים📊', 'callback_data' => "user"]],
                        [['text' => '✏️הודעה למשתמשים✏️', 'callback_data' => "message"]]
                    ]
                ])
            ]);
        } elseif ($callbackData == "message") {
            file_put_contents("bcpv.txt", "bc");
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "אוקי עכשיו שלח לי את הטקסט שלך",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '❌ביטול❌', 'callback_data' => "home"]]
                    ]
                ])
            ]);
        }
    } elseif (strpos($callbackData, "_") !== false) {
        // אם הכפתור הוא ניווט
        list($searchQuery, $page) = explode("_", $callbackData);
        $results = searchFiles($searchQuery);
        $page = (int)$page; // המרת הדף למספר שלם
        list($pagedResults, $totalPages) = paginateResults($results, $page);

        // שמור את הנתונים של הדף הנוכחי
        $inlineKeyboard = [];
        foreach ($pagedResults as $result) {
            $buttonText = (strlen($result['name']) > 60) ? mb_substr($result['name'], 0, 60) . '' : $result['name'];
            $inlineKeyboard[] = [
                ['text' => $buttonText, 'url' => "https://t.me/filter_sratim3_bot?start=" . $result['id']]
            ];
        }

        // כפתורי ניווט
        $navigationButtons = [];
        if ($page > 1) {
            $navigationButtons[] = ['text' => '<--', 'callback_data' => $searchQuery . "_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navigationButtons[] = ['text' => '-->', 'callback_data' => $searchQuery . "_" . ($page + 1)];
        }

        if (!empty($navigationButtons)) {
            $inlineKeyboard[] = $navigationButtons;
        }

        // עדכון ההודעה עם התוצאות החדשות
        sendRequest('editMessageText', [
            'chat_id' => $chatId2,
            'message_id' => $messageId,
            'text' => "התוצאות שנמצאו עבור <b>'$searchQuery'</b>\n (דף $page מתוך $totalPages)",
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
            'parse_mode' => 'HTML'
        ]);
    }
}

// בדיקה אם המנהל שלח הודעה לאחר שלחץ על 'הודעה למשתמשים'
if (isset($update['message']['text']) && $update['message']['text'] != '/פאנל' && $update['message']['from']['id'] == $adminId) {
    if (file_exists("bcpv.txt") && trim(file_get_contents("bcpv.txt")) == "bc") {
        file_put_contents("bcpv.txt", "none");
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "ההודעה שלך נשלחה בהצלחה לכל המשתמשים!",
        ]);
        $textToSend = $update['message']['text']; // קח את ההודעה מהמשתמש
        $all_member = fopen("users.txt", "r");
        while (!feof($all_member)) {
            $user = fgets($all_member);
            if (!empty(trim($user))) {
                sendRequest('sendMessage', [
                    'chat_id' => trim($user),
                    'text' => $textToSend,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            }
        }
    }
}

// הוספת תנאי לפתיחת הפאנל בניהול
if (isset($update['message']['text']) && $update['message']['text'] == '/פאנל' && $message['from']['id'] == $adminId) {
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "ברוך הבא מנהל👨‍⚕️",
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '📊משתמשים📊', 'callback_data' => "user"]],
                [['text' => '✏️הודעה למשתמשים✏️', 'callback_data' => "message"]]
            ]
        ])
    ]);
}


#
##
###
##
#

elseif ($callbackData == "זכויות יוצרים") {
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "<b>זירה היקרים, תפסיקו לרדוף אחרינו.
הקבצים שנשלחים בבוט לא אנחנו העלנו אלא לקחנו קבצים שכבר קיימים בטלגרם.</b>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                    [['text' => 'חזרה',
                    'callback_data' => 'בית']]
                    ]
                ])
            ]);
        }


elseif ($callbackData == "בית") {
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "<b>היי👋
אני בוט עם מאגר ענק של סרטים וסדרות😎

כדי לחפש סרט/סדרה פשוט שלחו את השם שלו בקבוצה של הבוט.

👨🏼‍💻מתכנת ראשי: @BOSS1480</b>",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode([
                
                'inline_keyboard' => [
    [['text' => 'למעבר לקבוצה של הבוט',
            'url' => 'https://t.me/bot_Search_movies']],
    [['text' =>  'מדריך לחיפוש בבוט',
            'callback_data' => 'מדריך'],
        ['text' => 'הודעה לזכויות יוצרים',
            'callback_data' => 'זכויות יוצרים']],
        [['text' => 'לערוץ העדכונים של הבוט',
            'url' => 'https://t.me/amoviesnww1o']],
        ]
        ])
    ]);
    }


elseif ($callbackData == "מדריך") {
            sendRequest('editMessageText', [
                'chat_id' => $chatId2,
                'message_id' => $messageId,
                'text' => "⚙️<b><u>מדריך לחיפוש </u></b><a href='https://t.me/filter_sratim3_bot'><b>ברובוט החיפוש</b></a>⚙️

כשאתם מבקשים סרט או סדרה יש דרך לבקש...
<b>צריך להוסיף סימן קריאה ( ! ) לפני מה שרוצים...</b>

<u>דוגמאות לחיפוש נכון</u>✔️
!אשמתי
!מהיר ועצבני

<u>ואלו הן דוגמאות לא נכונות</u>❌
יש הארי פוטר?
!אפשר הארי פוטר
!יש את הסרט הארי פוטר?

הבנתם?
מעולה! 
<a href='https://t.me/bot_Search_movies'><b>נסו עכשיו בקבוצה!</b></a>

לא הבנתם⁉️
אל תדאגו ‼️
אנחנו לא משאירים אתכם לבד.
עדיין יש את הצוות המעולה שלנו שתמיד יענה לבקשות⚡
פשוט זה עוד דרך חכמה למענה מהיר יותר ‍",
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true, 
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                    [['text' => 'חזרה',
                    'callback_data' => 'בית']]
                    ]
                ])
            ]);
        }
        
?>
