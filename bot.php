<?php
// bot.php
require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';
$BOT_TOKEN = $config['bot']['8400549163:AAHcikqmNCsJj_l1-7h9SPfC6HYPt1445vk'];
$API_URL = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$adminTelegramId = $config['6152335873'];

// ёрдамчи функция8400549163:AAHcikqmNCsJj_l1-7h9SPfC6HYPt1445vk
function apiRequest($method, $params = []) {
    global $API_URL;
    $url = $API_URL . $method;
    $opts = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($params),
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, false, $context);
    return $result ? json_decode($result, true) : null;
}

// kiruvchi update'ni o'qish
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    // testing mumkin: echo file_get_contents('php://input');
    exit;
}

// --- message handling ---
if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $from = $message['from'] ?? [];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // userni saqlash / yangilash
    $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, name) VALUES (?, ?)");
        $stmt->execute([$chat_id, $from['first_name'] ?? null]);
        $user_id = $pdo->lastInsertId();
    } else {
        $user_id = $user['id'];
    }

    // /start
    if ($text === '/start') {
        $reply = "Assalomu alaykum! 👋\nTelefon ta'mirlash servisimiza xush kelibsiz.\n\n🔧 Navbat olish uchun tugma: '🔧 Navbat olish'\n📋 Mening navbatlarim uchun: '📋 Mening navbatlarim'";
        $keyboard = [
            'keyboard' => [
                [['text' => "🔧 Navbat olish"]],
                [['text' => "📋 Mening navbatlarim"]],
            ],
            'resize_keyboard' => true
        ];
        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $reply,
            'reply_markup' => $keyboard
        ]);
        exit;
    }

    // Kontakt (telefon) yuborish so'rovi uchun misol (ixtiyoriy)
    if ($text === '/phone') {
        $keyboard = [
            'keyboard' => [
                [['text' => '📱 Telefon raqamni yuborish', 'request_contact' => true]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Iltimos, telefon raqamingizni jo'nating:",
            'reply_markup' => $keyboard
        ]);
        exit;
    }

    // telefon contact qabul qilish
    if (isset($message['contact'])) {
        $contact = $message['contact'];
        // tekshir: chat_id bilan mismatch bo'lmasligi
        $phone = $contact['phone_number'];
        $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE telegram_id = ?");
        $stmt->execute([$phone, $chat_id]);
        apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "Rahmat! Telefon raqamingiz saqlandi: {$phone}"]);
        exit;
    }

    // "Navbat olish" bosilganda — vaqt so'raladi
    if ($text === '🔧 Navbat olish') {
        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Navbat olmoqchi bo'lgan kun va vaqtni yozing.\nFormat: YYYY-MM-DD HH:MM (masalan: 2025-12-05 15:30)"
        ]);
        exit;
    }

    // vaqt shakliga mos matn kelgan bo'lsa — navbat yaratish
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $text)) {
        $appointment_time = $text . ':00';
        // bloklanganligini tekshir
        $stmt = $pdo->prepare("SELECT * FROM blocks WHERE user_id = ? AND until_at > NOW() LIMIT 1");
        $stmt->execute([$user_id]);
        if ($stmt->fetch()) {
            apiRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "Afsuski, siz hozir navbat olishingiz mumkin emas — cheklov mavjud. Iltimos admin bilan bog'laning."
            ]);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO appointments (user_id, appointment_time) VALUES (?, ?)");
        $stmt->execute([$user_id, $appointment_time]);
        $appointment_id = $pdo->lastInsertId();

        $inline = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Tasdiqlayman', 'callback_data' => "confirm_{$appointment_id}"],
                    ['text' => '❌ Bekor qilaman', 'callback_data' => "cancel_{$appointment_id}"]
                ]
            ]
        ];

        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Navbat saqlandi: {$appointment_time}\nIltimos tasdiqlang.",
            'reply_markup' => $inline
        ]);

        // adminga xabar
        apiRequest('sendMessage', [
            'chat_id' => $adminTelegramId,
            'text' => "Yangi navbat: user_id={$user_id}, tg={$chat_id}, time={$appointment_time}, appointment_id={$appointment_id}"
        ]);
        exit;
    }

    // Mening navbatlarim
    if ($text === '📋 Mening navbatlarim') {
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE user_id = ? AND appointment_time >= NOW() ORDER BY appointment_time ASC");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "Sizda aktiv navbatlar mavjud emas."]);
        } else {
            $msg = "Sizning navbatlaringiz:\n";
            foreach ($rows as $r) {
                $msg .= "ID: {$r['id']} — {$r['appointment_time']} — status: {$r['status']}\n";
            }
            apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $msg]);
        }
        exit;
    }

    // admin komandalar (soddalashtirilgan) - /unblock <telegram_id>
    if ($chat_id == $adminTelegramId && strpos($text, '/unblock') === 0) {
        $parts = explode(' ', $text);
        if (isset($parts[1])) {
            $tg = intval($parts[1]);
            $stmt = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ?");
            $stmt->execute([$tg]);
            $u = $stmt->fetch();
            if ($u) {
                $pdo->prepare("DELETE FROM blocks WHERE user_id = ?")->execute([$u['id']]);
                apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "User {$tg} unblock qilindi."]);
            } else {
                apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "User topilmadi."]);
            }
        }
        exit;
    }
}

// --- callback_query handling (inline tugmalar) ---
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'];
    $chat_id = $cb['message']['chat']['id'];
    $message_id = $cb['message']['message_id'];
    $from_id = $cb['from']['id'];

    if (preg_match('/^(confirm|cancel)_(\d+)$/', $data, $m)) {
        $action = $m[1];
        $appointment_id = intval($m[2]);

        $stmt = $pdo->prepare("SELECT a.*, u.telegram_id FROM appointments a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
        $stmt->execute([$appointment_id]);
        $app = $stmt->fetch();
        if (!$app) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Navbat topilmadi']);
            exit;
        }

        if ($app['telegram_id'] != $from_id) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Bu navbat sizga tegishli emas']);
            exit;
        }

        if ($action === 'confirm') {
            $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ?")->execute([$appointment_id]);
            apiRequest('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "Navbat tasdiqlandi: {$app['appointment_time']}"
            ]);
            apiRequest('sendMessage', ['chat_id' => $adminTelegramId, 'text' => "User {$from_id} navbatni tasdiqladi: ID {$appointment_id}"]);
        } else {
            $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$appointment_id]);
            apiRequest('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "Navbat bekor qilindi."
            ]);
            apiRequest('sendMessage', ['chat_id' => $adminTelegramId, 'text' => "User {$from_id} navbatni bekor qildi: ID {$appointment_id}"]);
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'OK']);
    }
}
