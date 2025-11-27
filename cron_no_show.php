<?php
// cron_no_show.php
require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';
$BOT_TOKEN = $config['bot']['8400549163:AAHcikqmNCsJj_l1-7h9SPfC6HYPt1445vk'];
$API_URL = "https://api.telegram.org/bot{$BOT_TOKEN}/";
$adminTelegramId = $config['6152335873'];

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

// 1) 60 daqiqa oldingi eslatma yuborish (yoki 59-61 oralig'ida)
$stmt = $pdo->prepare("
    SELECT a.*, u.telegram_id
    FROM appointments a
    JOIN users u ON u.id = a.user_id
    WHERE a.status IN ('pending','confirmed')
      AND a.appointment_time BETWEEN (NOW() + INTERVAL 59 MINUTE) AND (NOW() + INTERVAL 61 MINUTE)
");
$stmt->execute();
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    $chat_id = $r['telegram_id'];
    $appointment_id = $r['id'];
    $text = "Sizning ta'mirlash navbatingizga 60 daqiqa qoldi: {$r['appointment_time']}\nKelasizmi?";
    $inline = [
        'inline_keyboard' => [
            [
                ['text' => 'Ha, kelaman ✅', 'callback_data' => "confirm_{$appointment_id}"],
                ['text' => 'Kelmayman ❌', 'callback_data' => "cancel_{$appointment_id}"]
            ]
        ]
    ];
    apiRequest('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $inline]);
}

// 2) appointment vaqtidan 15 daqiqa o'tib ham kelinmaganlarni no_show qilish
$stmt = $pdo->prepare("
    SELECT a.*, u.telegram_id
    FROM appointments a
    JOIN users u ON u.id = a.user_id
    WHERE a.status IN ('pending','confirmed')
      AND a.appointment_time <= (NOW() - INTERVAL 15 MINUTE)
");
$stmt->execute();
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    $appointment_id = $r['id'];
    $user_id = $r['user_id'];
    $telegram_id = $r['telegram_id'];

    // no_show ga o'tkazish va attempts ++
    $pdo->prepare("UPDATE appointments SET status = 'no_show', attempts = attempts + 1 WHERE id = ?")->execute([$appointment_id]);

    // user bo'yicha umumiy no_show soni
    $stmt2 = $pdo->prepare("SELECT COUNT(*) as cnt FROM appointments WHERE user_id = ? AND status = 'no_show'");
    $stmt2->execute([$user_id]);
    $noShowCount = intval($stmt2->fetchColumn());

    if ($noShowCount >= 2) {
        // 7 kun bloklash
        $until = (new DateTime())->add(new DateInterval('P7D'))->format('Y-m-d H:i:s');
        // blok mavjud bo'lmasa, qo'shamiz
        $stmtCheck = $pdo->prepare("SELECT id FROM blocks WHERE user_id = ? AND until_at > NOW()");
        $stmtCheck->execute([$user_id]);
        if (!$stmtCheck->fetch()) {
            $pdo->prepare("INSERT INTO blocks (user_id, until_at, reason) VALUES (?, ?, ?)")->execute([$user_id, $until, 'no_show_limit']);
        }
        apiRequest('sendMessage', ['chat_id' => $telegram_id, 'text' => "Siz 2 marta kelmaganligingiz sababli 7 kun davomida navbat olishingiz cheklandi."]);
    } else {
        apiRequest('sendMessage', ['chat_id' => $telegram_id, 'text' => "Siz belgilangan vaqtda kelmadingiz. Iltimos keyingi safar diqqat qiling."]);
    }

    // adminga malumot
    apiRequest('sendMessage', ['chat_id' => $adminTelegramId, 'text' => "No-show: user_id={$user_id}, appointment={$appointment_id}, total_no_shows={$noShowCount}"]);
}
