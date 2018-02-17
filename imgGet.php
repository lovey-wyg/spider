<?php
/**
 * Created by PhpStorm.
 * User: wyg
 * Date: 2018/2/11 0011
 * Time: 下午 7:27
 */
header('Content-Type:text/html;charset=utf-8');
date_default_timezone_set('PRC');
ini_set('max_execution_time', '0');
$baseConf = include_once './conf.php';
$index = include_once './key.php';
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client;

class imgGet
{
    protected $targetUrl = 'http://www.mmjpg.com/more/';

    private $baseConf = null;

    private $imgCount = 0;

    private $index = [];

    public $imgUrls = [];

    private $key = 0;

    public function __construct($conf, $index)
    {
        $this->baseConf = $conf;
        $this->index = $index;
    }

    private function getRandIp(){
        $ip = mt_rand(58, 220) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255);
        return $ip;
    }

    public function initCurl($url, $option = []){
        $ip = $this->getRandIp();
        $ch = curl_init();
        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; W…) Gecko/20100101 Firefox/58.0',
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_TIMEOUT => 100,
            CURLOPT_HTTPHEADER => [
                'X-FORWARDED-FOR:' . $ip,
                'CLIENT-IP:' . $ip
            ],
            CURLOPT_HEADER => 0,
            CURLOPT_REFERER => 'http://www.mmjpg.com/',
            CURLOPT_COOKIE => ''
        ];
        if (!empty($option)){
            foreach ($option as $key => $v){
                if (array_key_exists($key, $curl)){
                    $curl[$key] = $v;
                }
                else{
                    $curl[] = $v;
                }
            }
        }
        curl_setopt_array($ch, $curl);
        return $ch;
    }

    public function getArrByUrl($url, $partten, $opt){
        $ch = $this->initCurl($url);
        $retStr = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode == 200){
            return $this->getArr($retStr, $partten, $opt);
        }else{
            $this->recordError($ch);
        }
    }

    public function curlMulti($urls = [], $dirName = '', $callback){
        $cmh = curl_multi_init();
        $max_curl_conn = $this->baseConf['max_curl_conn'];
        if (count($urls) < $max_curl_conn){
            $max_curl_conn = count($urls);
        }
        $i = 0;
        $conn = [];
        while ($i < $max_curl_conn){
            $ch = $this->initCurl($urls[$i]);
            $conn[$urls[$i]] = $ch;
            curl_multi_add_handle($cmh, $ch);
            $i++;
        }
        do {
            while(($execrun = curl_multi_exec($cmh, $active)) == CURLM_CALL_MULTI_PERFORM);
            if($execrun != CURLM_OK) {
                break;
            }
            while($done = curl_multi_info_read($cmh)) {
                //执行完毕的curl资源
                $oldCh = $done['handle'];
                $info = curl_getinfo($oldCh);
                if ($info['http_code'] == 200) {
                    $url = $info['url'];
                    $this->key = array_search($url, $urls);
                    $content = curl_multi_getcontent($oldCh);
                    self::$callback($content, $dirName);
                    //新建一个curl资源并加入并发队列
                    if($i < count($urls)) {
                        $newCh = $this->initCurl($urls[$i]);
                        $conn[$urls[$i]] = $newCh;
                        curl_multi_add_handle($cmh, $newCh);
                        $i++;
                    }
                    curl_multi_remove_handle($cmh, $oldCh);
                    curl_close($oldCh);
                } else {
                    $this->recordError($oldCh);
                }
            }
        } while($active);
        return true;
    }

    private function getArr($retStr, $partten, $opt){
        preg_match_all($partten, $retStr, $match);
        if (empty($match[0])){
            return false;
        }
        $retArr = [];
        foreach ($opt as $key => $val){
            $key++;
            $retArr[$val] = $match[$key];
        }
        return $retArr;
    }

    private function recordError($ch){
        echo curl_errno($ch),":",curl_error($ch),"\n";
    }

    public function start(){
        //设置匹配标签页所有主题href的正则
        $partten = '/<li.*?><a.*?href=\"(.*?)\".*?><img.*?src=\"(.*?)\".*?alt=\"(.*?)\".*?\/?>(.*?)<\/a><i.*?>.*?(\d+).*?<\/i><\/li>/i';
        $opt = [
            'href',
            'src',
            'alt',
            'title',
            'num'
        ];
        $theme = $this->getArrByUrl($this->targetUrl, $partten, $opt);
        if ($theme){
            foreach ($theme['href'] as $key => $url){
                if ($key < $this->index['par']){
                    continue;
                }
                //设置当前主题标识
                $par = $key;
                //设置匹配当前主题href的正则
                $partten = '/<li.*?><a.*?href=\"(.*?)\".*?><img.*?src=\"(.*?)\".*?alt=\"(.*?)\".*?\/?><\/a>.*?<\/li>/i';
                $opt = [
                    'href',
                    'src',
                    'alt',
                ];
                $parDir = $theme['title'][$key];
                $subThe = $this->getArrByUrl($url, $partten, $opt);
                if ($subThe){
                    $page = 1;
                    while (count($subThe['href']) < $theme['num'][$key]){
                        $page++;
                        $nextPageUrl = $url . '/' . $page;
                        $nextSub = $this->getArrByUrl($nextPageUrl, $partten, $opt, 'src', 'alt');
                        if ($nextSub){
                            foreach ($opt as $val){
                                $subThe[$val] = array_merge($subThe[$val], $nextSub[$val]);
                            }
                        }
                        else{
                        //如果分页连接失效，套图数量自动减少，防止死循环
                            $theme['num'][$key]--;
                        }
                    }
                    foreach ($subThe['href'] as $subKey => $subUrl){
                        if ($subKey < $this->index['son']){
                            continue;
                        }
                        //设置当前分类标识
                        $son = $subKey;
                        //设置匹配当前主题所有分类href的正则
                        $partten = '/<div.*?><a.*?href=\"(.*?)\".*?><img.*?src=\"(.*?)\".*?alt=\"(.*?)\".*?\/?><\/a><\/div>/i';
                        $opt = [
                            'href',
                            'src',
                            'alt',
                        ];
                        $sonDir = $subThe['alt'][$subKey];
                        $bigImgUrls = $this->getArrByUrl($subUrl, $partten, $opt);
                        $page = 1;
                        while ($page < $this->baseConf['img_num']){
                            $page++;
                            $nextPageUrl = $subUrl . '/' . $page;
                            $sub = $this->getArrByUrl($nextPageUrl, $partten, $opt);
                            if (empty($sub['href'])){
                                continue;
                            }
                            foreach ($opt as $val){
                                $bigImgUrls[$val] = array_merge($bigImgUrls[$val], $sub[$val]);
                            }
                        }
                        $dir = $parDir . '\\' . $sonDir;
                        $this->curlMulti($bigImgUrls['src'],'\\mn' . '\\' . $dir, 'getImg');
                        $this->saveIndex($par, $son);
                    }
                }
                else{
                  //当前主题无法访问 页面404
                  continue;
                }
            }
        }
        else{
            //标签页无内容或匹配失败
            echo "----------\n";
            echo 'res: not found';
            echo "\n";
            echo 'code :404';
            echo "\n";
            return;
        }

    }

    //下载图片
    protected function getImg($content, $dirName){
        $dir = $this->baseConf['baseImgDir'] . $dirName . $this->imgUrls['name'][$this->key];
        if ($this->chackDir($dir)){
            $name = $this->reName();
            $fileName = $dir . '/' . $name;
            if ($this->saveImg($fileName, $content)){
                $this->printMsg($this->imgCount);
            };
        }
    }

    //获取图片路径
    protected function getImgUrl($content, $dirName = ''){
        $crawler = new Crawler();
        $crawler->addHtmlContent($content);
        $retArr = $crawler->filterXPath("//div[@id='content']/a/img")->extract(['src','alt']);
        if (!empty($retArr)){
            $this->imgUrls['urls'][] = $retArr[0][0];
            $this->imgUrls['name'][] = explode(' ', trim($retArr[0][1]))[0];
        }
    }

    private function creatUrls($maxNum, $maxPage){
        $page = 1;
        $urls = [];
        while ($page <= $maxNum){
            $baseUrl = 'http://www.mmjpg.com/mm/';
            $baseUrl .= $page;
            $pageSize = 1;
            while ($pageSize <= $maxPage){
                $url = $baseUrl;
                $url .= '/' . $pageSize;
                $urls[] = $url;
                $pageSize++;
            }
            $page++;
        }
        return $urls;
    }

    public function test(){
        $url = 'http://www.mmjpg.com/more/';
        $client = new Client([
            'timeout' => 10,
            'header'  => [
                'User_Agent' => 'Mozilla/5.0 (Windows NT 6.1; W…) Gecko/20100101 Firefox/58.0'
            ]
        ]);
        $response = $client->request('GET',$url)->getBody()->getContents();
        $crawler = new Crawler();
        $crawler->addHtmlContent($response);
        $data = [];
        $crawler->filterXPath("//div[@class='tag']/ul/li")->each(function (Crawler $node) use (&$data){
            $data['href'][] = $node->filterXPath("//a")->attr('href');
            $data['imgUrl'][] = $node->filterXPath("//a//img")->attr('src');
            $data['name'][] = $node->filterXPath("//a")->text();
            $str = $node->filterXPath("//i")->text();
            $data['num'][] = preg_match('/\d+/', $str, $match) ? $match[0] : 0;

        });
    }

    public function reName($pre = '', $ext = 'jpg'){
        $code = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 's', 'm', 'n', 'x', 'v', 'z', 't'];
        $n1 = substr(rand(11111, 99999), 0, 1);
        $n2 = substr(rand(11111, 99999), 1, 1);
        $n3 = substr(rand(11111, 99999), 2, 1);
        $n4 = substr(rand(11111, 99999), 3, 1);
        $n5 = substr(rand(11111, 99999), 4, 1);
        $n = count($code) - 1;
        $name = $n1 . $code[rand(0, $n)] . $n2 . $code[rand(0, $n)] . $n3 . $code[rand(0, $n)] . $n4 . $code[rand(0, $n)] . $n5 . $code[rand(0, $n)];
        return !empty($pre) ? $pre . $name . '.' . $ext : $name . '.' . $ext;
    }

    public function chackDir($dir){
        return  is_dir ($dir) or $this->chackDir(dirname( $dir )) and  mkdir ( $dir , 0777);
    }

    public function saveImg($fileName, $content){
        $handle = fopen($fileName, 'w');
        fwrite($handle, $content);
        fclose($handle);
        return true;
    }

    public function saveIndex($par = 0, $son = 0){
        $index = ['par' => $par, 'son' => $son];
        $str = "<?php\n";
        $str .=
"/**
 * Created by PhpStorm.
 * User: wyg
 * Date: 2018/2/11 0011
 * Time: 下午 7:27
 */\n\n";
        $str .= 'return ';
        if (false === file_put_contents("key.php", $str.var_export($index, true) . ';')){
            return false;
        };
        return true;
    }

    //输出信息
    private function printMsg(&$imgCount){
        $imgCount++;
        echo "----------\n";
        echo 'success: ok';
        echo "\n";
        echo 'httpcode :200';
        echo "\n";
        echo 'imgnum :' . $imgCount;
        echo "\n";
    }

    //快速爬虫，无法按照标签建立子文件夹
    public function run($maxNum = 1261, $maxPage = 50, $path = '/download/'){
        $urls = self::creatUrls($maxNum, $maxPage);
        $ok = self::curlMulti($urls, '','getImgUrl');
        if (true === $ok){
            self::curlMulti($this->imgUrls['urls'], $path,'getImg');
            echo '||--------all ok end';
            return true;
        }
    }

    public function go(){
        $tarUrl = 'http://www.mmjpg.com/more/';
        $ch = $this->initCurl($tarUrl);
        $response = curl_exec($ch);
        curl_close($ch);
        $crawler = new Crawler();
        $crawler->addHtmlContent($response);
        $crawler->filterXPath("//div[@class='tag']/ul/li")->each(function (Crawler $node) use (&$theme){
            $theme['urls'][] = $node->filterXPath("//a")->attr('href');
            $theme['name'][] = $node->filterXPath("//a")->text();
            $theme['src'][] = $node->filterXPath("//a//img")->attr('src');
            $str = $node->filterXPath("//i")->text();
            $theme['num'][] = preg_match('/\d+/', $str, $match) ? $match[0] : 0;
        });
        if (!empty($theme['urls'])){
            foreach ($theme['urls'] as $key => $url){
                if ($key < $this->index['par']){
                    continue;
                }
                $par = $key;    //设置当前主题标识
                $parDir = $theme['name'][$key];
                $ch = $this->initCurl($url);
                $response =curl_exec($ch);
                curl_close($ch);
                $crawler->clear();
                $crawler->addHtmlContent($response);
                $crawler->filterXPath("//div[@class='pic']/ul/li")->each(function (Crawler $node) use (&$subThe){
                    $subThe['urls'][] = $node->filterXPath("//a")->attr('href');
                    $subThe['name'][] = $node->filterXPath("//a//img")->attr('alt');
                    $subThe['src'][] = $node->filterXPath("//a//img")->attr('src');
                });
                $page = 1;
                $maxPage = ceil($theme['num'][$key]  / 15);
                while (count($subThe['urls']) < $theme['num'][$key]){
                    $page++;
                    $nextPageUrl = $url . '/' . $page;
                    $ch = $this->initCurl($nextPageUrl);
                    $response =curl_exec($ch);
                    curl_close($ch);
                    $crawler->clear();
                    $crawler->addHtmlContent($response);
                    $crawler->filterXPath("//div[@class='pic']/ul/li")->each(function (Crawler $node) use (&$subThe){
                        $subThe['urls'][] = $node->filterXPath("//a")->attr('href');
                        $subThe['name'][] = $node->filterXPath("//a//img")->attr('alt');
                        $subThe['src'][] = $node->filterXPath("//a//img")->attr('src');
                    });
                    //防止由于本套图不存在造成死循环
                    if ($page == $maxPage){
                        break;
                    }
                }
                foreach ($subThe['urls'] as $subKey => $subUrl){
                    if ($subKey < $this->index['son']){
                        continue;
                    }
                    $son = $subKey; //设置当前分类标识
                    $sonDir = $subThe['name'][$subKey];
                    $ch = $this->initCurl($subUrl);
                    $response =curl_exec($ch);
                    curl_close($ch);
                    $crawler->clear();
                    $crawler->addHtmlContent($response);
                    $crawler->filterXPath("//div[@id='content']/a")->each(function (Crawler $node) use (&$bigImgUrls){
                        $bigImgUrls['src'][] = $node->filterXPath("//img")->attr('src');
                        $bigImgUrls['name'][] = $node->filterXPath("//img")->attr('alt');
                    });
                    $page = 1;
                    while ($page < $this->baseConf['img_num']){
                        $page++;
                        $nextPageUrl = $subUrl . '/' . $page;
                        $ch = $this->initCurl($nextPageUrl);
                        $response =curl_exec($ch);
                        curl_close($ch);
                        $crawler->clear();
                        $crawler->addHtmlContent($response);
                        $crawler->filterXPath("//div[@id='content']/a")->each(function (Crawler $node) use (&$bigImgUrls){
                            $bigImgUrls['src'][] = $node->filterXPath("//img")->attr('src');
                            $bigImgUrls['name'][] = $node->filterXPath("//img")->attr('alt');
                        });
                    }
                    $dir = $parDir . '/' . $sonDir;
                    $this->curlMulti($bigImgUrls['src'],'/path/' . $dir, 'getImg');
                    $this->saveIndex($par, $son);
                    unset($bigImgUrls);
                }
                unset($subThe);
            }
        }
        else{
            //标签页无内容或匹配失败
            echo "----------\n";
            echo 'success: fail';
            echo "\n";
            echo '||--------all 404 end';
            return;
        }
    }
}

$imgGet = new imgGet($baseConf, $index);

//$imgGet->start();
//$urls = $imgGet->creatUrls(1261, 50);
$imgGet->run(1261, 50);
//$imgGet->go();




