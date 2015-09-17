<?php

namespace BugsnagExport;

use GuzzleHttp\Psr7\Uri;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @param string[] $row
 * @return string
 */
function format_csv_row(array $row) {
    $parts = [];
    foreach ($row as $cell) {
        if (
            strpos($cell, "\n") !== false ||
            strpos($cell, ',') !== false ||
            strpos($cell, '"') !== false ||
            ($cell === '' && count($row) == 1)
        ) {
            $cell = '"' . str_replace('"', '""', $cell) . '"';
        }
        $parts[] = $cell;
    }
    return join(',', $parts);
}

/**
 * @param mixed[] $array
 * @return string[]
 */
function flatten_array(array $array) {
    $res = [];
    foreach ($array as $k => $v) {
        if (is_array($v)) {
            foreach (flatten_array($v) as $k2 => $v2) {
                $res["$k.$k2"] = "$v2";
            }
        } else {
            $res[$k] = "$v";
        }
    }
    return $res;
}

/**
 * @param array $data
 * @return string
 */
function to_csv(array $data) {
    $rows = [];
    $merged = [];
    foreach ($data as $row) {
        $row = flatten_array($row);
        $rows[] = $row;
        $merged = $merged ? array_intersect_key($merged, $row) : $row;
    }
    $columns = array_keys($merged);
    $result = format_csv_row($columns) . "\n";
    foreach ($rows as $d) {
        $row = [];
        foreach ($columns as $col)
            $row[] = array_key_exists($col, $d) ? $d[$col] : '';
        $result .= format_csv_row($row) . "\n";
    }
    return $result;
}

class BugsnagAPI {
    private $client;

    /**
     * @param string $token
     * @param string $username
     * @param string $password
     */
    function __construct($token, $username, $password) {
        $uri = new Uri('https://api.bugsnag.com/');
        $config = [];
        if ($token) {
            $config['headers']['Authorization'] = "token $token";
        } else if ($username && $password) {
            $uri = $uri->withUserInfo(rawurlencode($username), rawurlencode($password));
        } else {
            die('Please specify either --token or --username and --password');
        }
        $config['base_uri'] = $uri;
        $this->client = new \GuzzleHttp\Client($config);
    }

    /**
     * @param string $path
     * @param int|null $limit
     * @return array
     */
    function get($path, $limit) {
        $limit = $limit === null ? PHP_INT_MAX : $limit;
        $result = [];
        $uri = new Uri($path);
        while ($uri && count($result) < $limit) {
            $response = $this->client->get($uri);
            $result = array_merge($result, \PureJSON\JSON::decode($response->getBody()->getContents()));
            $uri = null;
            foreach ($response->getHeader('link') as $link_) {
                foreach (explode(',', $link_) as $link) {
                    list($l, $r) = explode('; ', $link, 2);
                    if ($r === 'rel="next"') {
                        $uri = new Uri(substr($l, 1, -1));
                        $uri = $uri->withScheme(null)->withHost(null);
                        break;
                    }
                }
            }
        }
        $result = array_slice($result, 0, $limit);
        return $result;
    }
}

function main() {
    $args = \Docopt::handle(<<<'s'
Usage:
  bugsnag-csv-export PATH [options]

Options:
  --token=AUTH_TOKEN     Authorization token
  --username=AUTH_USER   Authorization username
  --password=AUTH_PASS   Authorization password
  --save=PATH            Save output to the specified file instead of stdout [default: php://output]
  --limit=LIMIT          Maximum number of rows [default: 1000]
s
    );

    $client = new BugsnagAPI(
        $args['--token'],
        $args['--username'],
        $args['--password']
    );

    file_put_contents($args['--save'], to_csv($client->get($args['PATH'], $args['--limit'])));
}

date_default_timezone_set(getenv('TZ') ?: 'UTC');

main();