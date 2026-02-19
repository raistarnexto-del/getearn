<?php
// --- الإعدادات الأساسية ---
$botToken = "8505457388:AAGZSyQjXYpBNO5ED0O3XMg6dF6vkKpwnis";
$firebaseUrl = "https://lolaminig-afea4-default-rtdb.firebaseio.com/users";
$adminId = "7384284034"; // !!! ضَع هنا الآيدي الخاص بك (يمكنك الحصول عليه من @userinfobot)

$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update) exit;

$message = $update['message'] ?? null;
$callback_query = $update['callback_query'] ?? null;

// --- الدوال المساعدة ---
function request($method, $params) {
    global $botToken;
    $ch = curl_init("https://api.telegram.org/bot$botToken/$method");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    return json_decode(curl_exec($ch), true);
}

function getDB($path) { global $firebaseUrl; return json_decode(file_get_contents("$firebaseUrl/$path.json"), true); }
function setDB($path, $data) {
    global $firebaseUrl;
    $ch = curl_init("$firebaseUrl/$path.json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
}

// --- معالجة الرسائل ---
if ($message) {
    $chatId = $message['chat']['id'];
    $text = $message['text'];

    if (strpos($text, "/start") === 0) {
        $user = getDB($chatId);
        if (!$user) {
            $ref = explode(" ", $text)[1] ?? null;
            $user = ['bal' => 0, 'clicks' => 0, 'power' => 1, 'last_claim' => time(), 'ref' => $ref];
            setDB($chatId, $user);
            if ($ref && $ref != $chatId) {
                $inviter = getDB($ref);
                $inviter['bal'] += 2.0; // مكافأة دعوة
                setDB($ref, $inviter);
                request("sendMessage", ['chat_id' => $ref, 'text' => "🎉 دخل شخص من رابطك! نزل لك 2 نقطة."]);
            }
        }
        showMain($chatId);
    }

    // لوحة الأدمن (تظهر لك فقط)
    if ($text == "/admin" && $chatId == $adminId) {
        $kb = ['inline_keyboard' => [
            [['text' => "📊 إحصائيات البوت", 'callback_data' => "adm_stats"]],
            [['text' => "💰 تعديل رصيد مستخدم", 'callback_data' => "adm_edit"]]
        ]];
        request("sendMessage", ['chat_id' => $chatId, 'text' => "🛠 لوحة التحكم السرية:", 'reply_markup' => json_encode($kb)]);
    }
}

// --- معالجة الأزرار ---
if ($callback_query) {
    $chatId = $callback_query['from']['id'];
    $data = $callback_query['data'];
    $msgId = $callback_query['message']['message_id'];
    $user = getDB($chatId);

    // 1. التعدين اليدوي
    if ($data == "mine") {
        $user['bal'] += 0.01;
        $user['clicks'] += 1;
        setDB($chatId, $user);
        request("answerCallbackQuery", ['callback_query_id' => $callback_query['id'], 'text' => "⛏ تم (+0.01)"]);
        editMain($chatId, $msgId, $user);
    }

    // 2. التعدين السحابي (تجميع تلقائي بناءً على الوقت)
    if ($data == "cloud") {
        $now = time();
        $diff = $now - $user['last_claim'];
        $earned = ($diff / 3600) * (0.05 * $user['power']); // يربح 0.05 في الساعة لكل مستوى قوة
        $user['bal'] += $earned;
        $user['last_claim'] = $now;
        setDB($chatId, $user);
        
        $kb = ['inline_keyboard' => [
            [['text' => "🆙 شراء باقة (زيادة القوة)", 'callback_data' => "shop"]],
            [['text' => "🔙 رجوع", 'callback_data' => "main"]]
        ]];
        request("editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "☁️ التعدين السحابي يعمل!\n⚡️ قوتك الحالية: x".$user['power']."\n💰 جمعت تلقائياً: ".round($earned, 4)."\n\n(كلما زادت القوة، زاد الربح وأنت نائم!)", 'reply_markup' => json_encode($kb)]);
    }

    // 3. المتجر (شراء باقات)
    if ($data == "shop") {
        $kb = ['inline_keyboard' => [
            [['text' => "📦 باقة x2 (بـ 50 نقطة)", 'callback_data' => "buy_2"]],
            [['text' => "📦 باقة x5 (بـ 100 نقطة)", 'callback_data' => "buy_5"]],
            [['text' => "🔙 رجوع", 'callback_data' => "cloud"]]
        ]];
        request("editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "🛒 متجر الباقات:\nرصيدك: ".round($user['bal'], 2), 'reply_markup' => json_encode($kb)]);
    }

    if (strpos($data, "buy_") === 0) {
        $p = (int)explode("_", $data)[1];
        $cost = ($p == 2) ? 50 : 100;
        if ($user['bal'] >= $cost) {
            $user['bal'] -= $cost;
            $user['power'] = $p;
            setDB($chatId, $user);
            request("answerCallbackQuery", ['callback_query_id' => $callback_query['id'], 'text' => "✅ تم شراء الباقة!", 'show_alert' => true]);
        } else {
            request("answerCallbackQuery", ['callback_query_id' => $callback_query['id'], 'text' => "❌ رصيدك غير كافٍ!", 'show_alert' => true]);
        }
    }

    // 4. الشروحات
    if ($data == "help") {
        $txt = "📖 **شرح البوت:**\n1. التعدين: اضغط واجمع نقاط يدوياً.\n2. السحابي: الربح يعمل تلقائياً كل ساعة.\n3. الإحالة: اربح 2 نقطة عن كل صديق.\n4. السحب: اطلب سحبك عند وصولك لـ 100 نقطة.";
        request("editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $txt, 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => "🔙 رجوع", 'callback_data' => "main"]]]])]);
    }

    // 5. وظائف الأدمن (التعديل)
    if ($data == "adm_edit" && $chatId == $adminId) {
        request("sendMessage", ['chat_id' => $chatId, 'text' => "أرسل الآيدي الخاص بالمستخدم الذي تريد تعديل رصيده ثم المبلغ، مثال:\n`123456789 1000`"]);
    }

    if ($data == "main") { showMain($chatId, $msgId); }
}

function showMain($chatId, $msgId = null) {
    $user = getDB($chatId);
    $txt = "💰 **منصة الربح المتكاملة**\n\n💵 رصيدك: ".round($user['bal'], 2)."\n⛏ ضغطاتك: ".$user['clicks']."\n⚡️ قوة التعدين: x".$user['power'];
    $kb = ['inline_keyboard' => [
        [['text' => "⛏ تعدين يدوي", 'callback_data' => "mine"]],
        [['text' => "☁️ تعدين سحابي", 'callback_data' => "cloud"], ['text' => "👥 دعوة", 'callback_data' => "invite"]],
        [['text' => "📖 شرح البوت", 'callback_data' => "help"], ['text' => "💳 سحب", 'callback_data' => "withdraw"]]
    ]];
    if ($msgId) {
        request("editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $txt, 'reply_markup' => json_encode($kb), 'parse_mode' => "Markdown"]);
    } else {
        request("sendMessage", ['chat_id' => $chatId, 'text' => $txt, 'reply_markup' => json_encode($kb), 'parse_mode' => "Markdown"]);
    }
}

function editMain($chatId, $msgId, $user) {
    $txt = "💰 **منصة الربح المتكاملة**\n\n💵 رصيدك: ".round($user['bal'], 2)."\n⛏ ضغطاتك: ".$user['clicks']."\n⚡️ قوة التعدين: x".$user['power'];
    $kb = ['inline_keyboard' => [[['text' => "⛏ اضغط مجدداً", 'callback_data' => "mine"]], [['text' => "🔙 رجوع", 'callback_data' => "main"]]]];
    request("editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $txt, 'reply_markup' => json_encode($kb), 'parse_mode' => "Markdown"]);
}
