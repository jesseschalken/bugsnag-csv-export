<?php

namespace BugsnagExport;

use GuzzleHttp\Psr7\Uri;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @param string[] $row
 * @return string
 */
function format_csv(array $row)
{
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
    return join(',', $parts) . "\n";
}

/**
 * @param mixed[] $array
 * @return string[]
 */
function flatten_array(array $array)
{
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
 * @param \Traversable $data
 * @return \Traversable
 */
function to_csv(\Traversable $data)
{
    $rows = [];
    $merged = [];
    foreach ($data as $row) {
        $row = flatten_array($row);
        $rows[] = $row;
        foreach ($row as $k => $v) {
            if (array_key_exists($k, $merged)) {
                $merged[$k]++;
            } else {
                $merged[$k] = 1;
            }
        }
    }
    // The most common columns come first
    arsort($merged, SORT_NUMERIC);
    $columns = array_keys($merged);
    yield format_csv($columns);
    foreach ($rows as $d) {
        $row = [];
        foreach ($columns as $col)
            $row[] = array_key_exists($col, $d) ? $d[$col] : '';
        yield format_csv($row);
    }
}

class BugsnagAPI
{
    private $client;

    /**
     * @param string $token
     * @param string $username
     * @param string $password
     */
    function __construct($token, $username, $password)
    {
        $uri = new Uri('https://api.bugsnag.com/');
        $config = [];
        if ($token) {
            $config['headers']['Authorization'] = "token $token";
        } else if ($username && $password) {
            $uri = $uri->withUserInfo($username, $password);
        } else {
            die('Please specify either --token or --username and --password');
        }
        $config['base_uri'] = $uri;
        $this->client = new \GuzzleHttp\Client($config);
    }

    function get($path, $params = [])
    {
        $uri = new Uri;
        $uri = $uri->withPath($path);
        foreach ($params as $k => $v)
            $uri = Uri::withQueryValue($uri, $k, $v);
        while ($uri) {
            print $uri . "\n";
            $response = $this->client->get($uri);
            if ($response->getStatusCode() != 200) {
                die('bugsnag returned error: ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
            }
            $json = \PureJSON\JSON::decode($response->getBody()->getContents());
            print count($json) . "\n";
            foreach ($json as $j)
                yield $j;
            $uri = null;
            foreach ($response->getHeader('link') as $link_) {
                foreach (explode(',', $link_) as $link) {
                    list($l, $r) = explode('; ', $link, 2);
                    if ($r === 'rel="next"') {
                        $uri = new Uri(substr($l, 1, -1));
                        $uri = new Uri($uri->getPath() . '?' . $uri->getQuery());
                        break;
                    }
                }
            }
        }
    }
}

function parse_time($time)
{
    $startTime = strtotime($time);
    if ($startTime === false)
        die("Could not understand '$time'");
    $dt = new \DateTime('@' . $startTime);
    $dt->setTimezone(new \DateTimeZone('UTC'));
    return $dt->format(\DateTime::ISO8601);
}

function find_project(BugsnagAPI $client, $accountName, $projectName)
{
    foreach ($client->get('/accounts') as $account) {
        if ($account['name'] === $accountName) {
            foreach ($client->get("/accounts/{$account['id']}/projects") as $project) {
                if ($project['name'] === $projectName) {
                    return $project['id'];
                }
            }
        }
    }
    die("Could not find project $accountName/$projectName");
}

function main()
{
    $args = \Docopt::handle(<<<'s'
Usage:
  bugsnag-csv-export [options]

Options:
  --account=ACCOUNT      Bugsnag account name
  --project=PROJECT      Bugsnag project name
  --token=AUTH_TOKEN     Authorization token
  --username=AUTH_USER   Authorization user
  --password=AUTH_PASS   Authorization pass
  --from=START_TIME      Start time for the query, must be understandable by PHP's strtotime()
  --until=END_TIME       End time for the query, must be understandable by PHP's strtotime()
  --save=PATH            Save output to the specified file instead of stdout
  --timezone=TIMEZONE    Interpret --from and --until using this timezone
s
    );

    $client = new BugsnagAPI(
        $args['--token'],
        $args['--username'],
        $args['--password']
    );

    if ($args['--timezone'])
        date_default_timezone_set($args['--timezone']);

    $params = ['per_page' => 100];
    if ($args['--from'])
        $params['start_time'] = parse_time($args['--from']);
    if ($args['--until'])
        $params['end_time'] = parse_time($args['--until']);

    $project = find_project($client, $args['--account'], $args['--project']);
    $data = $client->get("/projects/{$project}/events", $params);

    $result = to_csv($data);
    if ($args['--save']) {
        file_put_contents($args['--save'], join('', iterator_to_array($result)));
    } else {
        foreach ($result as $row)
            print $row;
    }
}

date_default_timezone_set(getenv('TZ') ?: 'UTC');

main();