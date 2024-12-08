<?php
session_start();
set_time_limit(0);
ignore_user_abort(true);

define('LOG_FILE', 'debug.log');
define('RETRY_DELAY', 120);
define('MAX_RETRIES', 3);
define('MISTRAL_API_KEY', ' ENTER YOUR API KEY ');
define('LOCK_FILE', 'queue_processing.lock');

function logMessage($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, LOG_FILE);
}

function callMistralAPI($prompt) {
    $apiUrl = 'https://api.mistral.ai/v1/chat/completions';

    $data = [
        'model' => 'mistral-large-2411',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . MISTRAL_API_KEY
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logMessage("Erreur API Mistral : " . $error);
        return null;
    }

    return json_decode($response, true);
}

function processQueue() {
    if (empty($_SESSION['queue'])) {
        logMessage("File d'attente vide");
        return false;
    }

    $article = array_shift($_SESSION['queue']);
    $prompt = "Analyse cet article et propose une chanson intelligente et créative sur le sujet:\n\nTitre: " . $article['title'] . "\n\nDescription: " . $article['description'] . "\n\nRéponds avec un commentaire sur l'article et les paroles d'une chanson originale sur le sujet.";
    
    $aiResponse = callMistralAPI($prompt);

    if ($aiResponse === null) {
        logMessage("Échec de l'appel API pour l'article : " . $article['title']);
        array_unshift($_SESSION['queue'], $article);
        return false;
    }

    $editoContent = date('Y-m-d') . " - " . $article['title'] . "\n\n";
    $editoContent .= "Selon Le Média: " . $article['title'] . "\n\n";
    $editoContent .= "Commentaire IA et chanson:\n" . $aiResponse['choices'][0]['message']['content'] . "\n\n";
    $editoContent .= "Description originale: " . $article['description'] . "\n\n";

    $editoFile = 'edito/' . date('Y-m-d', strtotime($article['pubDate'])) . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $article['title']) . '.txt';
    if (file_put_contents($editoFile, $editoContent) === false) {
        logMessage("Erreur : Impossible d'écrire l'édito pour " . $article['title']);
        return false;
    }

    file_put_contents('last_processed.txt', date('Y-m-d H:i:s'));
    logMessage("Édito créé : " . $article['title']);
    return true;
}

if (file_exists(LOCK_FILE)) {
    logMessage("Le traitement est déjà en cours.");
    exit;
}

file_put_contents(LOCK_FILE, '');

try {
    while (!empty($_SESSION['queue'])) {
        $result = processQueue();
        if ($result) {
            // Attendre la réponse de l'IA avant de passer à l'article suivant
            sleep(RETRY_DELAY);
        } else {
            // En cas d'erreur, attendre un peu avant de réessayer
            sleep(30);
        }
    }
} finally {
    unlink(LOCK_FILE);
}

logMessage("Traitement de la file d'attente terminé");