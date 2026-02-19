<?php

// --- بيانات البوت وقاعدة البيانات ---
$botToken = "8505457388:AAGZSyQjXYpBNO5ED0O3XMg6dF6vkKpwnis";
$firebaseUrl = "https://lolaminig-afea4-default-rtdb.firebaseio.com/users";

// استقبال البيانات من تيليجرام (Webhook)
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$message = $update['message'] ?? null;
$callback_query = $update['callback_query'] ?? null;

// --- معالجة الرسائل النصية ---
if ($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];
    $firstName = $message['from']['first_name'];

    if (strpos($text, "/start") === 0) {
        // التحقق من الإحالات (Referrals)
        $refId = str_replace("/start ", "", $text);
        if ($refId == "/start") { $refId = null; }

        $user = getFirebaseData("$firebaseUrl/$chatId.json");

        if (!$user) {
            $user = [
                'name' => $firstName,
                'balance' => 0.00,
                'clicks' => 0,
                'invitedBy' => $refId
            ];
            updateFirebaseData("$firebaseUrl/$chatId.json", $user);

            // مكافأة الشخص الذي قام بالدعوة (0.50 نقطة)
            if ($refId && $refId != $chatId) {
                $inviter = getFirebaseData("$firebaseUrl/$refId.json");
                if ($inviter) {
                    $inviter['balance'] += 0.50;
                    updateFirebaseData("$firebaseUrl/$refId.json", $inviter);
                    sendMessage($refId, "🔔 صديق جديد انضم عبر رابطك! حصلت على 0.50 نقطة.");
                }
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => "⛏️ ابدأ التعدين (0.01)", 'callback_data' => "mine"]],
                [['text' => "👥 دعوة الأصدقاء", 'callback_data' => "invite"]],
                [['text' => "📊 رصيدي", 'callback_data' => "stats"]],
                [['text' => "💳 سحب الأرباح", 'callback_data' => "withdraw"]]
            ]
        ];

        sendMessage($chatId, "💰 **أهلاً بك في بوت التعدين العملاق**\n\nقم بالضغط على الزر لبدء الجمع، أو ادعُ أصدقاءك لزيادة رصيدك بسرعة!", $keyboard);
    }
}

// --- معالجة الضغط على الأزرار ---
if ($callback_query) {
    $chatId = $callback_query['from']['id'];
    $data = $callback_query['data'];
    $msgId = $callback_query['message']['message_id'];
    $cbId = $callback_query['id'];

    $user = getFirebaseData("$firebaseUrl/$chatId.json");

    if ($data == "mine") {
        $user['balance'] += 0.01; // تجميع صعب للمستخدم ومربح لك
        $user['clicks'] += 1;
        updateFirebaseData("$firebaseUrl/$chatId.json", $user);

        answerCallback($cbId, "⛏️ تم التعدين بنجاح! (+0.01)");
        editMessage($chatId, $msgId, "✅ **رصيدك الحالي:** " . number_format($user['balance'], 2) . " نقطة\n⛏️ **إجمالي الضغطات:** " . $user['clicks'], [
            'inline_keyboard' => [[['text' => "⛏️ اضغط مرة أخرى", 'callback_data' => "mine"]]]
        ]);
    }

    if ($data == "invite") {
        $me = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getMe"), true);
        $botUser = $me['result']['username'];
        $link = "https://t.me/$botUser?start=$chatId";
        sendMessage($chatId, "🔗 **رابط الإحالة الخاص بك:**\n\n`$link`\n\nاربح 0.50 نقطة عن كل صديق!");
        answerCallback($cbId, "");
    }

    if ($data == "stats") {
        sendMessage($chatId, "📊 **إحصائيات حسابك:**\n\n💰 الرصيد: " . number_format($user['balance'], 2) . "\n⛏️ الضغطات: " . $user['clicks']);
        answerCallback($cbId, "");
    }

    if ($data == "withdraw") {
        if ($user['balance'] < 100) {
            answerCallback($cbId, "⚠️ رصيدك أقل من 100 نقطة!", true);
        } else {
            sendMessage($chatId, "✅ وصل رصيدك للحد الأدنى! أرسل لقطة شاشة لرصيدك إلى المالك ليتم الدفع لك.");
            answerCallback($cbId, "");
        }
    }
}

// --- الوظائف المساعدة (Helper Functions) ---

function sendMessage($chatId, $text, $markup = null) {
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($markup) $data['reply_markup'] = json_encode($markup);
    request($url, $data);
}

function editMessage($chatId, $msgId, $text, $markup = null) {
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/editMessageText";
    $data = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($markup) $data['reply_markup'] = json_encode($markup);
    request($url, $data);
}

function answerCallback($id, $text, $alert = false) {
    global $botToken;
    $url = "https://api.telegram.org/bot$botToken/answerCallbackQuery";
    request($url, ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert]);
}

function request($url, $params) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function getFirebaseData($url) {
    return json_decode(file_get_contents($url), true);
}

function updateFirebaseData($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
