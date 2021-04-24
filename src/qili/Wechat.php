<?php
/**
 * 2021/04/24
 * 齐力信息技术 王
 * 1481173027@qq.com
 */

namespace qili;

use think\cache;
use think\Exception;


/**
* 微信
*/
class Wechat
{


	/**
	* @Title (自有变量)
	*/
	public $table;					//	表名
    public $wechatAppid;			//	应用Appid (应用包含 公众号,小程序,开放平台)
    public $wechatAppsecret;		//	应用Appserect (应用包含 公众号,小程序,开放平台)
    public $wechatMchid;			//	商户号
    public $wechatPrivatekey;		//	商户秘钥


	/**
	* @Title (构造函数)
	*/
    public function __construct($group = 1) {

		//	分组
		$this->group	=	$group;

		//	获取配置
		$web = get_addon_config('epay')["wechat"];

		//	证书文件夹
		$certPath	=	str_replace("\\","/",str_replace("public","",getcwd()) . "addons");

        $this->wechatAppid				=	$web["app_id"];
        $this->wechatAppsecret			=	$web["app_secret"];
        $this->wechatMchid				=	$web["mch_id"];
        $this->wechatPrivatekey			=	$web["key"];
		$this->certPath					=	$certPath;
		$this->cert_client				=	$certPath . $web["cert_client"];
		$this->cert_key					=	$certPath . $web["cert_key"];

    }

	/**
	* @Title (设置cache)
	*/
	public function setCache($key,$data,$time){
		Cache::set($key,$data,$time);
	}


	/**
	* @Title (获取cache)
	*/
	public function getCache($key){
		return Cache::get($key);
	}

	
	/**
	* @Title (获取AccessToken)
	*/
    public function getAccessToken(){
		
		//	判断有没有cache
		if(!$this->getCache("wechatAccessToken_".$this->group)){
			
			//	请求地址
			$url	=	'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid='.$this->wechatAppid.'&secret='.$this->wechatAppsecret;
			
			//	返回数据
			$data	=	$this -> curlGet($url);
			
			//	如果存在返回值
			if($data["access_token"]){

				//	设置cache
				$this->setCache("wechatAccessToken_".$this->group,$data["access_token"],3600);
				
			}else{

				//	抛出异常
				halt($data);
			}

		}

		//	返回值
        return $this->getCache("wechatAccessToken_".$this->group);

    }
	

	/**
	* @Title (获取JsapiTicket)
	*/
    public function getJsapiTicket(){
		
		//	判断有没有cache
		if(!$this->getCache("wechatJsapiTicket_".$this->group)){

			//	请求地址
			$url 	=	'https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token='.$this -> getAccessToken().'&type=jsapi';
			
			//	请求结果
			$data	=	$this -> curlGet($url);
			
			//	如果存在返回值
			if($data["ticket"]){

				//	设置cache
				$this->setCache("wechatJsapiTicket_".$this->group,$data["ticket"],$data["expires_in"]);
				
			}else{
				
				//	抛出异常
				throw new Exception($data);
			}

		}

        return $this->getCache("wechatJsapiTicket_".$this->group);
       
    }


	/**
	* @Title (生成signature)
	*/
    public function getSignature($appId,$timestamp,$nonceStr,$url){
        $jsapi_ticket =  $this -> getJsapiTicket();
        $string1 = "jsapi_ticket={$jsapi_ticket}&noncestr={$nonceStr}&timestamp={$timestamp}&url={$url}";
        $signature = sha1($string1);
        return $signature;
    }



	/**
	* @Title (下载文件到本地)
	*/
    public function curlDownload($url,$name){

		//	初始化curl
        $ch	=	curl_init ();  
        curl_setopt ( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );  
        curl_setopt ( $ch, CURLOPT_SSL_VERIFYPEER, false );  
        curl_setopt ( $ch, CURLOPT_URL, $url );  
        ob_start ();  
        curl_exec ( $ch );  
        $return_content	=	ob_get_contents ();  
        ob_end_clean ();  
        $return_code	=	curl_getinfo ( $ch, CURLINFO_HTTP_CODE );
        $filename		=	"uploads/{$name}";
        $fp				=	@fopen($filename,"a");
        fwrite($fp,$return_content);
        // 关闭URL请求
        curl_close($ch);
		$url	=	"/uploads/{$name}";
        return "{$url}";
    }




	/**
	* @Title (退款)
	* @param out_trade_no 订单号码
	* @param total_fee	  订单金额
	* @param refund_fee	  退款金额
	*/
	public function refund($out_trade_no,$total_fee){

		$arr['appid']			=	$this->wechatAppid;
		$arr['mch_id']			=	$this->wechatMchid;
		$arr['nonce_str']		=	$this->getNonceStr();
		$arr['out_trade_no']	=	$out_trade_no;
		$arr['out_refund_no']	=	$this->getNonceStr();
		$arr['total_fee']		=	$total_fee * 100;
		$arr['refund_fee']		=	$total_fee * 100;
		$arr['sign']			=	$this->MakeSign($arr);

		//	数组转换xml
		$xml	=	$this->ToXml($arr);

		//	微信退款地址
		$url	=	"https://api.mch.weixin.qq.com/secapi/pay/refund";

		//	请求结果
		$data	=	$this->curlPostSSL($url,$xml);
		
		if($data["result_code"]	==	"SUCCESS"){
			return true;
		}else{
			return false;
		}

	}
	
	


	/**
	* @Title (JSAPI支付)
	* @param orderNumber	订单号码
	* @param price			金额
	* @param openid			微信用户openid
	* @param notify_url		支付回调地址
	*/
	public function nativePay($orderNumber,$price,$notify_url){
		
		//	微信公众平台appid
		$arr['appid']			=	$this->wechatAppid;
		
		//	微信商户号
		$arr['mch_id']			=	$this->wechatMchid;
		
		//	随机字符串
		$arr['nonce_str']		=	$this->getNonceStr();

		//	描述
		$arr['body']			=	"扫码付款";

		//	订单号码
		$arr['out_trade_no']	=	$orderNumber; 

		//	订单金额
		$arr['total_fee']		=	$price * 100;
		
		//	ip
		$arr['spbill_create_ip']=	request()->ip();
		
		//	回调地址
		$arr['notify_url']		=	$notify_url;
		
		//	下单类型
		$arr['trade_type']		=	"NATIVE";
		
		//	生成签名
		$arr['sign']			=	$this->MakeSign($arr);
		
		//	转成xml
		$url	=	"https://api.mch.weixin.qq.com/pay/unifiedorder";

		//	获取返回结果
		$result	=	$this->postXmlCurl($this->ToXml($arr),$url);

		//	解析xml
		$result		=	$this->FromXml($result);	

		if($result["result_code"] == "SUCCESS"){
			return $result;
		}else{
			return false;
		}

	}





	/**
	* @Title (JSAPI支付)
	* @param orderNumber	订单号码
	* @param price			金额
	* @param openid			微信用户openid
	* @param notify_url		支付回调地址
	*/
	public function jsPay($orderNumber,$price,$openid,$notify_url){

		//	微信公众平台appid
		$arr['appid']				=	$this->wechatAppid;
		//	微信商户号
		$arr['mch_id']				=	$this->wechatMchid;
		//	随机字符串
		$arr['nonce_str'] 			=	$this->getNonceStr();	
		//	订单描述
		$arr['body']				=	$orderNumber;
		//	订单号码			
		$arr['out_trade_no']		=	$orderNumber;
		//	订单金额		
		$arr['total_fee']			=	$price * 100;
		//	用户IP			
		$arr['spbill_create_ip']	=	request()->ip();
		//	回调地址			
		$arr['notify_url']			=	$notify_url;
		//	支付类型
		$arr['trade_type']			=	"JSAPI";
		//	openid
		$arr['openid']				=	$openid;
		//	生成签名			
		$arr['sign']				=	$this->MakeSign($arr);					
		//	请求接口
		$rs							=	$this->FromXml($this->postXmlCurl($this->ToXml($arr),"https://api.mch.weixin.qq.com/pay/unifiedorder"));

		//	判断返回
		if($rs['result_code'] == "SUCCESS"){
			//验证签名
			$result["appId"]		=	$this->wechatAppid;				//公告号APPID
			$result["nonceStr"]		=	$rs['nonce_str'];				//随机字符串
			$result["package"]		=	"prepay_id=".$rs['prepay_id'];	//支付签名(特殊签名 前面加prepay_id=)	prepay_id值 只会在统一下单成功时返回
			$result["timeStamp"]	=	time();							//当前时间戳
			$result["signType"]		=	"MD5";							//加密类型  默认MD5
			$result["paySign"]  	=	$this->MakeSign($result);		//生成签名 与微信比较  如果签名有误,则支付失败
			$result["result_code"]	=	"SUCCESS";

			return $result;
		}else{
			return $rs;
		}
	}



	/**
	* @Title (APP支付)
	*/
	public function appPay($orderNumber,$price,$notify_url){

		$arr['appid']           =   $this->wechatAppid;
		$arr['mch_id']          =   $this->wechatMchid;
		$arr['nonce_str']       =   $this->getNonceStr();
		$arr['out_trade_no']    =	$orderNumber;
		$arr['spbill_create_ip']=   request()->ip();
		$arr['total_fee']       =   $price*100;
		$arr['trade_type']      =   "APP";
		$arr['notify_url']      =   $notify_url;
		$arr['body']            =   "消费金额";
		$arr['sign']            =   $this->MakeSign($arr);

		$url                    =   "https://api.mch.weixin.qq.com/pay/unifiedorder";
		$xml                    =   $this->ToXml($arr);
		$resultData				=	$this->FromXml($this->postXmlCurl($xml,$url));

		//	调用支付回调
		if($resultData['prepay_id']){

			//验证签名
			$result["appid"]		=	$this->wechatAppid;						//	公告号APPID
			$result["noncestr"]		=	$resultData['nonce_str'];				//	随机字符串
			$result["timestamp"]	=	time();									//	当前时间戳
			$result["partnerid"]	=	$resultData['mch_id'];
			$result["prepayid"]		=	$resultData['prepay_id'];
			$result["package"]		=	"Sign=WXPay";							//	支付签名(特殊签名 前面加prepay_id=)
			$result["sign"]			=	$this->MakeSign($result);				//	生成签名 与微信比较  如果签名有误,则支付失败

			$result["result"]		=	"SUCCESS";

			return $result;

		}else{

			return $resultData;
		}
		
	}
	
	



	/**
	* @Title (企业付款到个人)
	* @param number	流水号
	* @param openid				openid
	* @param price				金额
	*/
	public function payment($number,$openid,$price,$desc = ""){

		//	获取
		$arr['mch_appid']			=	$this->wechatAppid;
		$arr['mchid']				=	$this->wechatMchid;
		$arr['nonce_str']			=	$this->getNonceStr();
		$arr['partner_trade_no']	=	$number;
		$arr['openid']				=	$openid;
		$arr['check_name']			=	"NO_CHECK";
		$arr['amount']				=	$price * 100;
		$arr['desc']				=	$desc ? : "分享报名奖励红包";
		$arr['spbill_create_ip']	=	request()->ip();
		$arr['sign']				=	$this->MakeSign($arr);

		//	将统一下单数组 转换xml
		$xml						=	$this->ToXml($arr);

		//	post xml 到微信退款接口
		$url	=	"https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers";//微信退款地址，post请求

		//	返回结果
		$result	=	$this->curlPostSSL($url,$xml);

		if($result["result_code"] == "SUCCESS"){
			return true;
		}else{
			return false;
		}

	}





	/**
	* | 企业付款到银行卡
	*	 退款单号 out_trade_no
	*	 交易金额 total_fee
	*	 退款金额 refund_fee
	*/
	public function payCard($partner_trade_no,$enc_bank_no,$enc_true_name,$bank_code,$amount){

		//	公钥证书
		$publicPem	=	$this->certPath . "/epay/certs/public.pem";

		$Rsa	=	new \Rsa($publicPem,"");

		//	$arr['mch_appid']		=	$this->wechatAppid;	//	appid
		$arr['mch_id']				=	$this->wechatMchid;		//	商户号
		$arr['nonce_str']			=	$this->getNonceStr();	//	随机字符串
		$arr['partner_trade_no']	=	$partner_trade_no;		//	订单号码
		$arr['enc_bank_no']			=	$Rsa->public_encrypt($enc_bank_no);			//	银行卡号
		$arr['enc_true_name']		=	$Rsa->public_encrypt($enc_true_name);			//	收款用户姓名
		$arr['bank_code']			=	$bank_code;				//	银行卡编号
		$arr['amount']				=	$amount*100;			//	打款金额 单位是分
		$arr['sign']				=	$this->MakeSign($arr);	//	签名


		//	将统一下单数组 转换xml
		$xml						=	$this->ToXml($arr);

		//post xml 到微信退款接口
		$url	=	"https://api.mch.weixin.qq.com/mmpaysptrans/pay_bank";//微信退款地址，post请求

		$result	=	$this->curlPostSSL($url,$xml);

		return $result;

	}





	/**
	* @Title (数组转XML)
	* @param xml	xml
	* @param url	请求地址
	*/
	public function stopNotify(){
		$result["return_code"]	=	"SUCCESS";
		$result["return_msg"]	=	"OK";
		$xml	=	$this->ToXml($result);
		echo($xml);
		exit;
	}
	




	/**
	* @Title (数组转XML)
	* @param xml	xml
	* @param url	请求地址
	*/
	public function ToXml($array)
	{
		if(!is_array($array) 
			|| count($array) <= 0)
		{
    		throw new Exception("数组异常");
    	}
    	$xml = "<xml>";
    	foreach ($array as $key=>$val)
    	{
    		if (is_numeric($val)){
    			$xml.="<".$key.">".$val."</".$key.">";
    		}else{
    			$xml.="<".$key."><![CDATA[".$val."]]></".$key.">";
    		}
        }
        $xml.="</xml>";
        return $xml; 
	}





	/**
	* @Title (从文件中读取信息并返回)
	*/
	public function readTofile($path){
		$filePath			=	str_replace("\\","/",getcwd()).$path;
		return file_get_contents($filePath);
	}





	/**
	* @Title (将信息写入文件)
	*/
	public function writeToFile($path_url,$data){

		//	文件地址
		$path			=	$this->certPath.$path_url;

		//	以读写方式打写指定文件，如果文件不存则创建
		if(($TxtRes = fopen ($path,"w+")) === false){
			throw new Exception("创建：".$path."失败,这是服务器权限问题!");
		}

		if(!fwrite ($TxtRes,$data)){
			throw new Exception("文件".$path."写入".$data."失败,这是服务器权限问题");
		}

		fclose($TxtRes);
		
		return true;
	}


	/**
	* @Title (获取微信RAS公钥 企业付款到银行卡时会用到)
	*/
	public function getPublicKey(){

		$url				=	"https://fraud.mch.weixin.qq.com/risk/getpublickey";
		$arr['mch_id']		=	$this->wechatMchid;
		$arr['nonce_str']	=	$this->getNonceStr();
		$arr['sign_type']	=	'MD5';
		$arr['sign']		=	$this->MakeSign($arr);
		$xml				=	$this->ToXml($arr);

		$data	=	$this->curlPostSSL($url,$xml);

		if($data["result_code"] == "SUCCESS"){

			//	存放公钥
			$path	=	"/epay/certs/public.pem";
			
			if($this->writeToFile($path,$data["pub_key"])){
				throw new Exception("文件".$path."创建成功");
			}else{
				throw new Exception("文件".$path."创建失败,请检查类库");
			}

		}

	}





	/*
	* @Title (循环检测目标 目录是否存在,否则创建)
	*/
	public function checkCreateDir($path){
		if (!file_exists($path)){
			$this->checkCreateDir(dirname($path)); 
			mkdir($path, 0755); 
		}
	}





	/*
	* @Title (将ZIP解压到指定目录下)
	* @scene 我这里解释一下使用场景 比如多小程序或多公众号的情况下 每个账号都会有自己的cert证书 但是一般客户是外行不懂这个,肯定不会自己操作这些 ,所以要在后台配置项中增加一个傻瓜式上传证书压缩包的地方,通过程序自动将商户证书文件解压到对应的账号证书目录下 用来实现对应账号的退款,付款等等操作
	* @param zipPath	压缩包地址
	* @param path		解压到
	*/
	public function zip($zipPath,$zipToPath = ""){

		//	解压到
		$zipToPath	=	$zipToPath ? : date("Ymd");

		//	压缩包地址
		$zipPath	=	str_replace("\\","/",getcwd()).$zipPath;

		if(pathinfo($zipPath)["extension"] != "zip"){
			throw new Exception("文件不是ZIP格式,无法解压");
		}

		//	解压地址
		$path		=	str_replace("\\","/",getcwd())."/uploads/zip/".$zipToPath;

		//	循环创建目录
		$this->checkCreateDir(dirname($path)); 

		//实例化ZipArchive类
		$zip = new ZipArchive();
		
		//打开压缩文件，打开成功时返回true
		if ($zip->open($zipPath) === true) {
			//解压文件到获得的路径a文件夹下
			$zip->extractTo($path);
			//关闭
			$zip->close();
			
			return true;
		} else {
			return false;
		}
	}


	/**
	* @Title (Xml转Array)
	*/
	public function FromXml($xml)
	{	
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $this->values = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);		
		return $this->values;
	}


	/**
	* @Title (企业付款到银行卡 银行卡编号)
	*/
	public function getCardCode($card_name = ""){	

		if($this->getCache("wechatCardCode")){

			$cardList	=	$this->getCache("wechatCardCode");

		}else{
			
			//	微信银行卡文档
			$url	=	"https://pay.weixin.qq.com/wiki/doc/api/tools/mch_pay.php?chapter=24_4&index=5";
			
			//	字符串
			$html	=	file_get_contents($url);
			
			//	正则获取微信文档中的编码
			preg_match_all("/<div class[^>].*?>(?>[^<\/div>]+|(?R))*<\/div>/is",$html,$result);
			
			//	正则获取微信文档中的编码
			preg_match_all("/<tr.*?>(.*?)<\/tr>/is",$result[0][3],$result);
			
			//	正则获取微信文档中的编码
			$result	=	$result[0];

			if($result){

				$cardList	=	[];

				unset($result[0]);

				foreach($result as $k=> &$v){

					$v	=	preg_replace("/(\s|\&nbsp\;|　|\xc2\xa0)/", " ", strip_tags($v));
					$v		=	explode("            ",$v);
					$v[0]	=	str_replace(" ","",$v[0]);
					
					array_push($cardList,[
						"name"	=>	$v[0],
						"code"	=>	$v[1],
					]);

				}

				$this->setCache("wechatCardCode",$cardList,time());

			}
		}


		if($card_name){
			foreach($cardList as $k => $v){
				if($card_name == $v["name"]){
					return $v;
				}
			}
			
			return false;
		}

		return $cardList;

	}


	/**
	* @Title (将数组格式化为url参数)
	*/
	public function ToUrlParams($array)
	{
		$buff = "";
		foreach ($array as $k => $v)
		{
			if($k != "sign" && $v != "" && !is_array($v)){
				$buff .= $k . "=" . $v . "&";
			}
		}
		$buff = trim($buff, "&");
		return $buff;
	}


	/**
	* @Title (生成签名)
	*/
	public function MakeSign($array)
	{
		//签名步骤一：按字典序排序参数
		ksort($array);
		$string	=	$this->ToUrlParams($array);
		//签名步骤二：在string后加入KEY
		$string	=	$string."&key=".$this->wechatPrivatekey;
		//签名步骤三：MD5加密
		$string	=	md5($string);
		//签名步骤四：所有字符转为大写
		$string	=	strtoupper($string);
		return $string;
	}


	/**
	* @Title (产生的随机字符串)
	*/
	public function getNonceStr($length = 32) 
	{
		$chars = "abcdefghijklmnopqrstuvwxyz0123456789";  
		$str ="";
		for ( $i = 0; $i < $length; $i++ )  {  
			$str .= substr($chars, mt_rand(0, strlen($chars)-1), 1);  
		} 
		return $str;
	}	


	
	/**
	* @Title (将图片上传至微信服务器)
	*/
	public function curlImg($images){
		
		$url		=	"https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=".$this->getAccessToken()."&type=image";		
		$ch1 		=	curl_init ();
		$timeout 	=	5;
		$real_path	=	"{$_SERVER['DOCUMENT_ROOT']}{$images}";
		
		$data= array("media"=>"@{$real_path}",'form-data'=>$file_info);
		curl_setopt ( $ch1, CURLOPT_URL, $url );
		curl_setopt ( $ch1, CURLOPT_POST, 1 );
		curl_setopt ( $ch1, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt ( $ch1, CURLOPT_CONNECTTIMEOUT, $timeout );
		curl_setopt ( $ch1, CURLOPT_SSL_VERIFYPEER, FALSE );
		curl_setopt ( $ch1, CURLOPT_SSL_VERIFYHOST, false );
		curl_setopt ( $ch1, CURLOPT_POSTFIELDS, $data );
		$result = curl_exec ( $ch1 );
		curl_close ( $ch1 );
		if(curl_errno()==0){
			$result=json_decode($result,true);
			return $result;
		}else {
			return false;
		}
	}



	/**
	* @Title (将文章转换为微信公众号文章)
	*/
	public function wechatText($content){
		$parrent = "/<[img|IMG].*?src='(.*?)'/";
		$str	=	html_entity_decode($content);
		preg_match_all($parrent,$str,$match);
		foreach( $match[1] as $v){
			$imgurl		=	$this->curlImg($v);
			$content	=	str_replace($v,$imgurl['url'],$content);
		}
		return ($content);
	}



	/**
	* @Title (curlGet)
	*/
    public function curlGet($url){

		$ch	=	curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$output	=	curl_exec($ch);
		curl_close($ch);
		$jsoninfo	=	json_decode($output, true);
		return $jsoninfo;
    }


	/**
	* @Title (curlPost)
	* @param url		请求地址
	* @param post_data	数据
	*/
    public function curlPost($url,$post_data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($post_data)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        $jsoninfo = json_decode($output, true);
        return $jsoninfo;
    }

	
	
	
	/**
	* @Title (以post方式提交xml到对应的接口url)
	* @param xml	xml
	* @param url	请求地址
	*/
	public function postXmlCurl($xml, $url, $useCert = false, $second = 30)
	{		
		$ch = curl_init();
		//设置超时
		curl_setopt($ch, CURLOPT_TIMEOUT, $second);
		curl_setopt($ch,CURLOPT_URL, $url);
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
		curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);//严格校验
		//设置header
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		//要求结果为字符串且输出到屏幕上
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		//post提交方式
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
		//运行curl
		$data = curl_exec($ch);
		//返回结果
		if($data){
			curl_close($ch);
			return $data;
		} else { 
			$error = curl_errno($ch);
			curl_close($ch);
		}
	}





	/**
	* @Title (携带证书的SSL POST请求)
	* @param url	url请求地址
	* @param data	数据
	* @param CURLOPT_SSL_VERIFYPEER	证书效验默认关闭
	*/
    public function curlPostSSL($url, $data , $CURLOPT_SSL_VERIFYPEER = false)
	{

		//	证书地址
		if($this->wechatId){

			$apiclient_cert	=	$path.'/uploads/cert/'.$this->wechatId.'/apiclient_cert.pem';
			$apiclient_key	=	$path.'/uploads/cert/'.$this->wechatId.'/apiclient_key.pem';

		}else{

			$apiclient_cert	=	$this->cert_client;
			$apiclient_key	=	$this->cert_key;

		}

		//	$rootca			=	$path.'/cert/rootca.pem';	//	废弃 

		//	初始化curl
		$ch		=	curl_init();

		//	设置请求地址
		curl_setopt($ch,CURLOPT_URL,$url);
		
		//	启用时会将头文件的信息作为数据流输出。 
		//	curl_setopt($ch,CURLOPT_HEADER,true);
		
		//	将信息以文件流的形式返回，而不是直接输出。 
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);

		//	证书检查 (关闭)
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,$CURLOPT_SSL_VERIFYPEER);

		//	证书类型PEM (支持的格式有"PEM" (默认值), "DER"和"ENG"。)
		curl_setopt($ch,CURLOPT_SSLCERTTYPE,'pem');
		
		//	PEM文件
		curl_setopt($ch,CURLOPT_SSLCERT,$apiclient_cert);		

		//	证书类型PEM
		curl_setopt($ch,CURLOPT_SSLCERTTYPE,'pem');

		//	PEM文件
		curl_setopt($ch,CURLOPT_SSLKEY,$apiclient_key);

		//	证书类型PEM
		//	curl_setopt($ch,CURLOPT_SSLCERTTYPE,'pem');

		//	一个保存着1个或多个用来让服务端验证的证书的文件名。这个参数仅仅在和 CURLOPT_SSL_VERIFYPEER 一起使用时才有意义。 .
		//	curl_setopt($ch,CURLOPT_CAINFO,$rootca);

		//	超时时间
		curl_setopt($ch,CURLOPT_TIMEOUT,30);

		curl_setopt($ch,CURLOPT_POST,true);

		//	设置请求数据
		curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
		
		//	返回数据
	   $result	=	curl_exec($ch);

	   //	如果有返回数据
	   if($result){

			//	关闭curl
			curl_close($ch);
			
			//	返回数据
			$result	=	$this->FromXml($result);
			
			return $result;

	   }else{

			$error	=	curl_errno($ch);
			
			throw new Exception("Curl错误:".$error.",请检查类库");

	   }
		
    }


	

}
?>