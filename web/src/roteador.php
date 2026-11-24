<?php
declare(strict_types=1);

/*
 * Roteador do servidor embutido do PHP, para desenvolver sem o Apache:
 *   php -S localhost:8080 -t web web/src/roteador.php
 * Faz o que o .htaccess faz no Apache: a pasta src/ não pode ser aberta pelo navegador.
 */
$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (preg_match('#^/src(/|$)#i', $caminho) || preg_match('#/\.ht#', $caminho)) {
    http_response_code(404);
    return true;
}
return false; // o resto (páginas, CSS, JS) o servidor embutido entrega normalmente
