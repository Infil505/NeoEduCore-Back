<?php
try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;port=5432;dbname=neoeducore",
        "postgres",
        "61635472"
    );
    echo "✅ Conexión OK\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
