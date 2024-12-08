<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('LOG_FILE', 'debug.log');
define('RSS_URL', 'https://api.lemediatv.fr/rss.xml');
define('MISTRAL_API_KEY', ' ENTER YOUR API KEY HERE ');

function logMessage($message) {
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, LOG_FILE);
}

function createDirectoryIfNotExists($dir) {
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            logMessage("Erreur : Impossible de créer le dossier $dir");
            return false;
        }
    }
    return true;
}

createDirectoryIfNotExists('info');
createDirectoryIfNotExists('edito');

function getAllRssItems($url) {
    $rss = @simplexml_load_file($url);
    if ($rss === false) {
        logMessage("Erreur : Impossible de charger le flux RSS");
        return [];
    }
    return $rss->channel->item ?? [];
}

function saveArticleAsJson($item) {
    $filename = 'info/' . preg_replace('/[^a-zA-Z0-9]+/', '_', $item->title) . '.json';
    $data = [
        'title' => (string)$item->title,
        'description' => (string)$item->description,
        'link' => (string)$item->link,
        'pubDate' => (string)$item->pubDate
    ];
    if (file_put_contents($filename, json_encode($data)) === false) {
        logMessage("Erreur : Impossible d'écrire le fichier JSON pour " . $item->title);
        return false;
    }
    return $filename;
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

function createEdito($article) {
    $prompt = "Analyse cet article et propose une chanson intelligente et créative sur le sujet:\n\nTitre: " . $article['title'] . "\n\nDescription: " . $article['description'] . "\n\nRéponds avec un commentaire sur l'article et les paroles d'une chanson originale sur le sujet.";
    
    $aiResponse = callMistralAPI($prompt);

    if ($aiResponse === null) {
        logMessage("Échec de l'appel API pour l'article : " . $article['title']);
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

    logMessage("Édito créé : " . $article['title']);
    return $editoFile;
}

$allItems = getAllRssItems(RSS_URL);
$articles = [];
foreach ($allItems as $item) {
    $jsonFile = saveArticleAsJson($item);
    if ($jsonFile) {
        $articles[] = json_decode(file_get_contents($jsonFile), true);
    }
}

$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

if (isset($_POST['create_edito'])) {
    $articleIndex = $_POST['article_index'];
    $editoFile = createEdito($articles[$articleIndex]);
    if ($editoFile) {
        header("Location: index.php?edito=" . urlencode($editoFile));
        exit;
    }
}

$selectedEdito = isset($_GET['edito']) ? $_GET['edito'] : null;
$editoContent = '';
if ($selectedEdito && file_exists($selectedEdito)) {
    $editoContent = file_get_contents($selectedEdito);
} else {
    $editoFiles = glob('edito/*.txt');
    rsort($editoFiles);
    if (!empty($editoFiles)) {
        $editoContent = file_get_contents($editoFiles[0]);
    }
}

$filteredArticles = array_filter($articles, function($article) use ($searchQuery) {
    return empty($searchQuery) || stripos($article['title'], $searchQuery) !== false || stripos($article['description'], $searchQuery) !== false;
});

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KBG.LIFE IA INFO</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            color: #333;
        }
        .container {
            width: 80%;
            margin: auto;
            overflow: hidden;
            padding: 20px;
        }
        header {
            background: #35424a;
            color: #ffffff;
            padding-top: 30px;
            min-height: 70px;
            border-bottom: #e8491d 3px solid;
        }
        header a {
            color: #ffffff;
            text-decoration: none;
            text-transform: uppercase;
            font-size: 16px;
        }
        header .site-name {
            float: left;
            font-size: 24px;
        }
        header form {
            float: right;
            margin-top: 15px;
        }
        header input[type="text"] {
            padding: 4px;
            height: 25px;
            width: 250px;
        }
        header input[type="submit"] {
            height: 35px;
            background: #e8491d;
            border: 0;
            padding-left: 20px;
            padding-right: 20px;
            color: #ffffff;
        }
        .edito {
            background: #ffffff;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .article-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .article {
            flex: 0 1 calc(33% - 20px);
            margin: 10px;
            padding: 15px;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .article h3 {
            margin-top: 0;
            color: #35424a;
        }
        .article .button {
            display: inline-block;
            padding: 10px 15px;
            background-color: #e8491d;
            color: #ffffff;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 10px;
        }
        footer {
            background: #35424a;
            color: #ffffff;
            text-align: center;
            padding: 20px;
            margin-top: 20px;
        }
        @media(max-width: 768px) {
            header .site-name, header form {
                float: none;
                text-align: center;
                width: 100%;
            }
            .article {
                flex: 0 1 100%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="site-name">KBG.LIFE IA INFO</div>
            <form action="index.php" method="get">
                <input type="text" name="search" placeholder="Rechercher..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                <input type="submit" value="Rechercher">
            </form>
        </div>
    </header>

    <div class="container">
        <div class="edito">
            <h2>Édito sélectionné</h2>
            <?php echo nl2br(htmlspecialchars($editoContent)); ?>
        </div>

        <h2>Articles récents</h2>
        <div class="article-list">
            <?php foreach ($filteredArticles as $index => $article): ?>
                <div class="article">
                    <h3><?php echo htmlspecialchars($article['title']); ?></h3>
                    <p><?php echo htmlspecialchars($article['description']); ?></p>
                    <?php
                    $editoFile = 'edito/' . date('Y-m-d', strtotime($article['pubDate'])) . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $article['title']) . '.txt';
                    if (file_exists($editoFile)):
                    ?>
                        <a href="?edito=<?php echo urlencode($editoFile); ?>" class="button">Voir l'édito</a>
                    <?php else: ?>
                        <form method="post" action="index.php">
                            <input type="hidden" name="article_index" value="<?php echo $index; ?>">
                            <input type="submit" name="create_edito" value="Créer l'édito" class="button">
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <footer>
        <p>Art is coding &copy; 2024</p>
    </footer>
</body>
</html>