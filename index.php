<?php
error_reporting(0);
require_once 'vendor/autoload.php';

use Sastrawi\Stemmer\StemmerFactory;

class DocumentIndexer
{
    private $conn;
    private $stemmer;

    public function __construct($servername, $username, $password, $dbname)
    {
        $this->conn = new mysqli($servername, $username, $password, $dbname);

        if ($this->conn->connect_error) {
            die("Connection failed: " . $this->conn->connect_error);
        }

        $stemmerFactory = new StemmerFactory();
        $this->stemmer = $stemmerFactory->createStemmer();
    }

    public function indexDocuments($documents)
    {
        foreach ($documents as $content) {
            $processedContent = $this->preprocessText($content);
            $processedContentEscaped = mysqli_real_escape_string($this->conn, $processedContent);

            if ($this->getExistingDocument($processedContentEscaped)) {
                continue;
            }

            $document_id = $this->insertDocument($processedContentEscaped);
            $terms = array_unique(array_filter(array_map('strtolower', preg_split("/[^a-zA-Z0-9]+/", $processedContent))));

            foreach ($terms as $term) {
                $termEscaped = mysqli_real_escape_string($this->conn, $term);

                if (!$term_id = $this->getTermId($termEscaped)) {
                    $term_id = $this->insertTerm($termEscaped);
                }

                $tfidf = $this->calculateTfidf($processedContent, $term, count($documents));
                $this->saveToDocumentTerms($document_id, $term_id, $tfidf);
            }
        }
    }

    private function preprocessText($content) {
        $content = strtolower($content);
        $content = preg_replace("/[^a-zA-Z0-9\s]/", "", $content);
        $content = $this->removeStopWords($content);
        $content = $this->stemText($content);
        return $content;
    }

    private function removeStopWords($content) {
        $stopWords = ["dan", "di", "yang", "untuk", "pada", "ke", "dengan"]; // Tambahkan stop words yang relevan
        $words = explode(" ", $content);
        $filteredWords = array_diff($words, $stopWords);
        return implode(" ", $filteredWords);
    }

    private function stemText($content) {
        $words = explode(" ", $content);
        $stemmedWords = array_map(function($word) {
            return $this->stemmer->stem($word);
        }, $words);

        return implode(" ", $stemmedWords);
    }

    private function getExistingDocument($content)
    {
        $stmt = $this->conn->prepare("SELECT id FROM documents WHERE content = ?");
        $stmt->bind_param("s", $content);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    private function insertDocument($content)
    {
        $stmt = $this->conn->prepare("INSERT INTO documents (content) VALUES (?)");
        $stmt->bind_param("s", $content);
        $stmt->execute();
        return $this->conn->insert_id;
    }

    private function getTermId($term)
    {
        $stmt = $this->conn->prepare("SELECT id FROM terms WHERE term = ?");
        $stmt->bind_param("s", $term);
        $stmt->execute();
        $existingTerm = $stmt->get_result()->fetch_assoc();

        if ($existingTerm) {
            return $existingTerm['id'];
        } else {
            return false;
        }
    }

    private function insertTerm($term)
    {
        $stmt = $this->conn->prepare("INSERT INTO terms (term) VALUES (?)");
        $stmt->bind_param("s", $term);
        $stmt->execute();
        return $this->conn->insert_id;
    }

    private function calculateTfidf($content, $term, $totalDocuments)
    {
        $tf = substr_count($content, $term) / str_word_count($content);
        $documentCount = $this->getDocumentCountForTerm($term);
        $idf = log($totalDocuments / ($documentCount + 1));
        return $tf * $idf;
    }

    private function saveToDocumentTerms($document_id, $term_id, $tfidf)
    {
        $stmt = $this->conn->prepare("INSERT INTO document_terms (document_id, term_id, tfidf) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tfidf = VALUES(tfidf)");
        $stmt->bind_param("iid", $document_id, $term_id, $tfidf);
        $stmt->execute();
    }

    private function getDocumentCountForTerm($term)
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as document_count FROM document_terms JOIN terms ON document_terms.term_id = terms.id WHERE terms.term = ?");
        $stmt->bind_param("s", $term);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['document_count'];
    }

    public function searchDocuments($searchTerm)
    {
        $searchTerm = $this->preprocessText($searchTerm);
        $searchTermEscaped = mysqli_real_escape_string($this->conn, $searchTerm);
        $terms = array_unique(array_filter(array_map('strtolower', preg_split("/[^a-zA-Z0-9]+/", $searchTermEscaped))));

        $documentScores = $this->calculateDocumentScores($terms);
        arsort($documentScores);

        return $this->fetchDocumentResults($documentScores);
    }

    private function calculateDocumentScores($terms)
    {
        $documentScores = array();

        foreach ($terms as $term) {
            $termEscaped = mysqli_real_escape_string($this->conn, $term);

            $stmt = $this->conn->prepare("SELECT document_id, SUM(tfidf) AS score FROM document_terms JOIN terms ON document_terms.term_id = terms.id WHERE terms.term = ? GROUP BY document_id");
            $stmt->bind_param("s", $termEscaped);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $document_id = $row['document_id'];
                $score = $row['score'];

                if (!isset($documentScores[$document_id])) {
                    $documentScores[$document_id] = 0;
                }

                $documentScores[$document_id] += $score;
            }
        }

        return $documentScores;
    }

    private function fetchDocumentResults($documentScores)
    {
        $results = array();

        foreach (array_keys($documentScores) as $document_id) {
            $stmt = $this->conn->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->bind_param("i", $document_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();

            if ($result) {
                $result['score'] = $documentScores[$document_id];
                $results[] = $result;
            }
        }

        return $results;
    }

    public function closeConnection()
    {
        $this->conn->close();
    }
}

// Example usage
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "p";

$documentIndexer = new DocumentIndexer($servername, $username, $password, $dbname);

$documents = array(
    "Kucing suka bermain.",
    "Anjing suka berlari di taman.",
    "Burung suka terbang tinggi.",
    "memberi makan anak kambing yang lapar"
);

$documentIndexer->indexDocuments($documents);

$searchTerm = "suka bermain";
$results = $documentIndexer->searchDocuments($searchTerm);

foreach ($results as $result) {
    echo "Document ID: " . $result['id'] . "<br>";
    echo "Content: " . $result['content'] . "<br>";
    echo "Score: " . $result['score'] . "<br><br>";
}

$documentIndexer->closeConnection();
?>
