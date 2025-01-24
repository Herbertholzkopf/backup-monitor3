<?php
class SimpleMarkdown {
    public function parse($text) {
        // Überschriften
        $text = preg_replace('/^#{6}\s+(.*?)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^#{5}\s+(.*?)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^#{4}\s+(.*?)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^#{3}\s+(.*?)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^#{2}\s+(.*?)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^#{1}\s+(.*?)$/m', '<h1>$1</h1>', $text);

        // Fettschrift
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        
        // Kursiv
        $text = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $text);
        
        // Links
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $text);
        
        // Listen
        $text = preg_replace('/^\-\s+(.*?)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/((?:<li>.*<\/li>)+)/', '<ul>$1</ul>', $text);
        
        // Code-Blöcke
        $text = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $text);
        
        // Inline-Code
        $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
        
        // Absätze
        $text = preg_replace('/^\s*$\n/m', '</p><p>', $text);
        $text = '<p>' . $text . '</p>';
        $text = str_replace('<p><h', '<h', $text);
        $text = str_replace('</h1></p>', '</h1>', $text);
        $text = str_replace('</h2></p>', '</h2>', $text);
        $text = str_replace('</h3></p>', '</h3>', $text);
        
        return $text;
    }
}

// Markdown-Datei einlesen
$markdownContent = file_get_contents('information.md');

// Markdown zu HTML konvertieren
$parser = new SimpleMarkdown();
$htmlContent = $parser->parse($markdownContent);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Markdown Viewer</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f3f4f6;
        }
        pre {
            background-color: #f4f4f4;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        code {
            background-color: #f4f4f4;
            padding: 2px 5px;
            border-radius: 3px;
        }
        img {
            max-width: 100%;
            height: auto;
        }
        ul {
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <?php echo $htmlContent; ?>
</body>
</html>