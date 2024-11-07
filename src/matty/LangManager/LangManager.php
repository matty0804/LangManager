<?php

declare(strict_types=1);

namespace matty\LangManager;

use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\Server;

use GeoIp2\Database\Reader as GeoIpReader;

/**
 * Class LangManager
 *
 * Handles multi-language support in the server, providing functions to manage language translations
 * and sending messages to players or the console.
 */
class LangManager{
	/** @var string[] $RAINBOW_PATTERN Defines the color pattern for rainbow text. */
	private static array $RAINBOW_PATTERN = [
		TextFormat::RED, TextFormat::GOLD, TextFormat::YELLOW, TextFormat::GREEN, TextFormat::AQUA, TextFormat::LIGHT_PURPLE
	];
	private const DEFAULT_COLOR = TextFormat::WHITE;
	
	private static ?self $instance = null;
	
	/** @var array $lang Language data array. */
	private array $lang = [];
	/** @var string[] $ipLangCache Caches the languages based on IP addresses. */
	private array $ipLangCache = [];
	/** @var GeoIpReader|null $geoIpReader GeoIP database reader for fetching country codes. */
	private ?GeoIpReader $geoIpReader = null;
	
	// ISO 639-1 language codes
	private const LANG_ENGLISH = "en";
	private const LANG_SPANISH = "es";
	private const LANG_HINDI = "hi";
	private const LANG_PORTUGUESE = "pt";
	private const LANG_CHINESE = "zh";
	private const LANG_RUSSIAN = "ru";
	private const LANG_FRENCH = "fr";
	private const LANG_GERMAN = "de";
	private const LANG_ARABIC = "ar";
	private const LANG_JAPANESE = "ja";
	
	public const LANG_DEFAULT = self::LANG_ENGLISH;
	
	/** @var string[] ALL_ISO_CODES Contains all supported ISO language codes. */
	public const ALL_ISO_CODES = [
		"English" => self::LANG_ENGLISH,
		"Spanish" => self::LANG_SPANISH,
		"Hindi" => self::LANG_HINDI,
		"Portuguese" => self::LANG_PORTUGUESE,
		"Chinese" => self::LANG_CHINESE,
		"Russian" => self::LANG_RUSSIAN,
		"French" => self::LANG_FRENCH,
		"German" => self::LANG_GERMAN,
		"Arabic" => self::LANG_ARABIC,
		"Japanese" => self::LANG_JAPANESE
	];
	
	/** @var Main|null */
	private ?Main $plugin;
	/** @var Config|null $log */
	private ?Config $log = null;
	
	// Log types
	private const LOG_TYPE_NOT_CASTABLE = 0;
	private const LOG_NO_ISO_MESSAGE = -1;
	
	/**
	 * Retrieves the singleton instance of LangManager.
	 *
	 * @return LangManager|null Instance of LangManager or null if not instantiated.
	 */
	public static function getInstance(): ?self{
		return self::$instance;
	}
	
	private function getPlugin(): ?Main{
		return $this->plugin;
	}
	
	/**
	 * Initializes the language manager and prepares it for use.
	 *
	 * @param Main $plugin
	 */
	public function __construct(Main $plugin){
		self::$instance = $this;
		$this->plugin = $plugin;
		$this->prepare();
	}
	
	public static function close(){
		if(self::$instance === null){
			return;
		}
		self::$instance->getPlugin()->getConfig()->save();
		self::$instance->log->save();
	}
	
	/**
	 * Logs language translation usage to the log file.
	 *
	 * @param int $type The type of log message.
	 * @param string $key The key for the language string.
	 * @param string $iso The ISO code for the language.
	 */
	private function log(int $type, string $key, string $iso): void{
		$this->log->set("[" . $type . "]: " . strtoupper($iso) . " " . $key, true);
	}
	
	/**
	 * Prepares the LangManager by loading configurations and initializing GeoIP support.
	 */
	private function prepare(): void{
		$plugin = Main::getInstance();
		$this->log = new Config($plugin->getServer()->getDataPath() . "LangManager.log", Config::ENUM);
		
		$plugin->saveResource(Main::MAXMIND_DB_RESOURCE, true);
		
		if(class_exists(GeoIpReader::class)){
			if(file_exists($plugin->getDataFolder() . Main::MAXMIND_DB_RESOURCE)){
				$this->geoIpReader = new GeoIpReader($plugin->getDataFolder() . Main::MAXMIND_DB_RESOURCE);
			}else{
				$plugin->getLogger()->warning("MaxMind database not found. Multi-language support is disabled");
			}
		}else{
			$plugin->getLogger()->warning("geoip library not found. Multi-language support is disabled");
		}
		foreach(self::ALL_ISO_CODES as $iso){
			$path = $plugin->getDataFolder() . "lang/" . $iso . ".ini";
			$plugin->saveResource($path, true);
			if(file_exists($path)){
				
				
				$this->lang[$iso] = (array) parse_ini_file($path, false, INI_SCANNER_NORMAL);
			}
		}
		foreach(self::ALL_ISO_CODES as $iso){
			foreach($this->lang["en"] as $key => $str){
				if(!isset($this->lang[$iso][$key])){
					$this->lang[$iso][$key] = $str;
				}
			}
		}
	}
	
	
	/**
	 * Sends a message directly to a player.
	 *
	 * @param string $key The translation key.
	 * @param mixed ...$params Additional parameters for the message.
	 * @api
	 */
	public static function send(string $key, ...$params): void{
		$msg = self::translate($key, ...$params);
		if(count($params) > 0 && $params[0] instanceof CommandSender){
			$params[0]->sendMessage($msg);
		}
	}
	
	/**
	 * Translates a string based on the key and parameters provided.
	 *
	 * @param string $key The translation key.
	 * @param mixed ...$params Additional parameters for the message.
	 * @return string The translated string.
	 * @api
	 */
	public static function translate(string $key, ...$params): string{
		if(self::$instance instanceof self){
			return self::$instance->translateContainer($key, ...$params);
		}
		return $key;
	}
	
	/**
	 * Handles the translation process, including player variables.
	 *
	 * @param string $key The translation key.
	 * @param mixed ...$params Additional parameters for the message.
	 * @return string The translated string.
	 * @internal
	 */
	private function translateContainer(string $key, ...$params): string{
		if(Main::getInstance() === null){
			self::$instance = null;
			return $key;
		}
		$iso = self::LANG_DEFAULT;
		
		$player = array_shift($params);
		if($player instanceof Player){
		
			if(!$player->isOnline()){
			
				$str = $this->translateString($key, $iso, ...$params);
			}else{
				
				$iso = $this->getPlayerLanguage($player);
				$str = $this->translateString($key, $iso, ...$params);
				$str = $this->translatePlayerVars($str, $player);
			}
			return $str;
		}else{
			
			
			array_unshift($params, $player);
			return $this->translateString($key, $iso, ...$params);
		}
	}
	
	/**
	 * Retrieves the language of a player.
	 *
	 * @param Player $player The player whose language is to be retrieved.
	 * @return string The ISO code of the player's language.
	 */
	public function getPlayerLanguage(Player $player): string{
		$iso = $this->getPlugin()->getConfig()->get($player->getName());
		if(!is_string($iso) || !in_array($iso, self::ALL_ISO_CODES)){
			$iso = $this->getLangByAddress($player->getNetworkSession()->getIp());
		}
		return $iso;
	}
	
	/**
	 * Changes the language of the player.
	 *
	 * @param Player $player The player whose language is to be changed.
	 * @param string $iso The ISO code of the languag
	 * @return string The new language chosen
	 */
	public function setPlayerLanguage(Player $player, string $iso): string{
		$this->getPlugin()->getConfig()->set($player->getName(), $iso);
		return array_search($iso,self::ALL_ISO_CODES) ?? "";
	}
	
	/**
	 * Checks if a given ISO language code exists in the list of supported languages.
	 *
	 * @param string $iso ISO code of the language
	 * @return bool
	 */
	public function isLanguageAvailable(string $iso) : bool{
		foreach(self::ALL_ISO_CODES as $language => $lang){
			if($iso === $lang){
				return true;
			}
		}
		return false;
	}
	
	/**
	 * Replaces placeholders in a string with the player's current state variables.
	 *
	 * @param string $str The string with placeholders to be replaced.
	 * @param Player $player The player object for variable replacement.
	 * @return string The modified string with player variables.
	 */
	private function translatePlayerVars(string $str, Player $player): string{
		$pos = $player->getPosition();
		return str_replace([
			"{X}", "{Y}", "{Z}", "{WORLD}", "{LEVEL}", "{HEALTH}", "{MAX_HEALTH}", "{PING}", "{NAME}", "{DISPLAY_NAME}"
		], [
			strval($pos->getFloorX()),
			strval($pos->getFloorY()),
			strval($pos->getFloorZ()),
			$player->getWorld()->getFolderName(),
			$player->getWorld()->getFolderName(),
			strval(round($player->getHealth())),
			strval(round($player->getMaxHealth())),
			strval($player->getNetworkSession()->getPing()),
			$player->getName(),
			$player->getDisplayName()
		], $str);
	}
	
	/**
	 * Replaces placeholders in a string with server information.
	 *
	 * @param string $str The string with placeholders to be replaced.
	 * @return string The modified string with server variables.
	 */
	private function translateServerVars(string $str): string{
		if(Main::getInstance() === null){
			self::$instance = null;
			return $str;
		}
		
		return str_replace([
			"{ONLINE}", "{MAX}", "{AVERAGE_TPS}"
		], [
			strval(count(Server::getInstance()->getOnlinePlayers())),
			strval(Server::getInstance()->getMaxPlayers()),
			strval(ceil(Server::getInstance()->getTicksPerSecondAverage()))
		], $str);
	}
	
	/**
	 * Retrieves a translated string based on a key and language code.
	 *
	 * @param string $key The translation key.
	 * @param string $iso The ISO code for the language.
	 * @param mixed ...$params Optional parameters for replacement.
	 * @return string The translated string with parameters.
	 */
	private function translateString(string $key, string $iso, ...$params): string{
		if(!isset($this->lang[$iso][$key])){
			$this->log(self::LOG_NO_ISO_MESSAGE, $key, $iso);
			if(isset($this->lang[self::LANG_DEFAULT][$key])){
				$iso = self::LANG_DEFAULT;
			}
		}
		
		$keyData = "[" . $iso . "][" . $key . "]";
		
		
		$str = $this->lang[$iso][$key] ?? $keyData;
		
		
		foreach($params as $i => $param){
			
			
			if(!is_string($param) && !is_float($param) && !is_int($param) && !($i === 0 && $param === null)){
				$this->log(self::LOG_TYPE_NOT_CASTABLE, $key, $iso);
				$param = "";
			}
			$str = str_replace("{%" . $i . "}",  strval($param), $str);
			$str = preg_replace('/' . preg_quote('{%}') . '/', strval($param), $str, 1);
			
			unset($params[$i]);
		}
		
		preg_match_all("/{/", $str, $haystack, PREG_OFFSET_CAPTURE);
		$originalStr = $str;
		foreach($haystack[0] as $needle){
			$substr = substr($originalStr, $needle[1] + 1);
			if(stripos($substr, "}") === false){
				continue;
			}
			$start = $needle[1] + 1;
			$pointer = $start;
			while($originalStr[$pointer] !== "}"){
				$pointer++;
			}
			$subkey = substr($originalStr, $start, $pointer - $start);
			if(isset($this->lang[$iso][$subkey]) && $subkey !== $key){
				$str = str_replace("{" . $subkey . "}", $this->translateString($subkey, $iso), $str);
			}
		}
		
		$str = $this->translateServerVars($str);
		$str = str_replace("{LINE}", TextFormat::EOL, $str);
		$str = $this->replaceHTMLTags($str);
		return TextFormat::colorize($str);
	}
	
	/**
	 * Gets the player's country code from their IP address.
	 *
	 * @param string $ip The player's IP address.
	 * @return string The two-letter country code (ISO 3166-1).
	 * @throws \Exception
	 */
	public function getCountryCode(string $ip): string{
		if(isset($this->countryCodeCache[$ip])){ //Inherent of player address
			return $this->countryCodeCache[$ip];
		}
		if($this->geoIpReader === null){
			return "US";
		}
		
		try{
			$record = $this->geoIpReader->country($ip);
		}catch(\Exception $e){
			return "US";
		}
		return $record->country->isoCode ?? self::LANG_DEFAULT;
	}
	
	/**
	 * Retrieves the language code based on the given IP address.
	 *
	 * @param string $ip The IP address to check.
	 * @return string The corresponding language code or the default language code.
	 */
	public function getLangByAddress(string $ip): string{
		if(isset($this->ipLangCache[$ip])){
			return $this->ipLangCache[$ip];
		}
		$country = $this->getCountryCode($ip);
		switch($country){
			case "DJ":
			case "ER":
			case "ET":
				$lang = "aa";
				break;
			case "AE":
			case "BH":
			case "DZ":
			case "EG":
			case "IQ":
			case "JO":
			case "KW":
			case "LB":
			case "LY":
			case "MA":
			case "OM":
			case "QA":
			case "SA":
			case "SD":
			case "SY":
			case "TN":
			case "YE":
				$lang = "ar";
				break;
			case "AZ":
				$lang = "az";
				break;
			case "BY":
				$lang = "be";
				break;
			case "BG":
				$lang = "bg";
				break;
			case "BD":
				$lang = "bn";
				break;
			case "BA":
				$lang = "bs";
				break;
			case "CZ":
				$lang = "cs";
				break;
			case "DK":
				$lang = "da";
				break;
			case "AT":
			case "CH":
			case "DE":
			case "LU":
				$lang = "de";
				break;
			case "MV":
				$lang = "dv";
				break;
			case "BT":
				$lang = "dz";
				break;
			case "GR":
				$lang = "el";
				break;
			case "AG":
			case "AI":
			case "AQ":
			case "AS":
			case "AU":
			case "BB":
			case "BW":
			case "CA":
			case "GB":
			case "IE":
			case "KE":
			case "NG":
			case "NZ":
			case "PH":
			case "SG":
			case "US":
			case "ZA":
			case "ZM":
			case "ZW":
				$lang = "en";
				break;
			case "AD":
			case "AR":
			case "BO":
			case "CL":
			case "CO":
			case "CR":
			case "CU":
			case "DO":
			case "EC":
			case "ES":
			case "GT":
			case "HN":
			case "MX":
			case "NI":
			case "PA":
			case "PE":
			case "PR":
			case "PY":
			case "SV":
			case "UY":
			case "VE":
				$lang = "es";
				break;
			case "EE":
				$lang = "et";
				break;
			case "IR":
				$lang = "fa";
				break;
			case "FI":
				$lang = "fi";
				break;
			case "FO":
				$lang = "fo";
				break;
			case "BE":
			case "FR":
			case "SN":
				$lang = "fr";
				break;
			case "IL":
				$lang = "he";
				break;
			case "IN":
				$lang = "hi";
				break;
			case "HR":
				$lang = "hr";
				break;
			case "HT":
				$lang = "ht";
				break;
			case "HU":
				$lang = "hu";
				break;
			case "AM":
				$lang = "hy";
				break;
			case "ID":
				$lang = "id";
				break;
			case "IS":
				$lang = "is";
				break;
			case "IT":
				$lang = "it";
				break;
			case "JP":
				$lang = "ja";
				break;
			case "GE":
				$lang = "ka";
				break;
			case "KZ":
				$lang = "kk";
				break;
			case "GL":
				$lang = "kl";
				break;
			case "KH":
				$lang = "km";
				break;
			case "KR":
				$lang = "ko";
				break;
			case "KG":
				$lang = "ky";
				break;
			case "UG":
				$lang = "lg";
				break;
			case "LA":
				$lang = "lo";
				break;
			case "LT":
				$lang = "lt";
				break;
			case "LV":
				$lang = "lv";
				break;
			case "MG":
				$lang = "mg";
				break;
			case "MK":
				$lang = "mk";
				break;
			case "MN":
				$lang = "mn";
				break;
			case "MY":
				$lang = "ms";
				break;
			case "MT":
				$lang = "mt";
				break;
			case "MM":
				$lang = "my";
				break;
			case "NP":
				$lang = "ne";
				break;
			case "AW":
			case "NL":
				$lang = "nl";
				break;
			case "NO":
				$lang = "no";
				break;
			case "PL":
				$lang = "pl";
				break;
			case "AF":
				$lang = "ps";
				break;
			case "AO":
			case "BR":
			case "PT":
				$lang = "pt";
				break;
			case "RO":
				$lang = "ro";
				break;
			case "RU":
			case "UA":
				$lang = "ru";
				break;
			case "RW":
				$lang = "rw";
				break;
			case "AX":
				$lang = "se";
				break;
			case "SK":
				$lang = "sk";
				break;
			case "SI":
				$lang = "sl";
				break;
			case "SO":
				$lang = "so";
				break;
			case "AL":
				$lang = "sq";
				break;
			case "ME":
			case "RS":
				$lang = "sr";
				break;
			case "SE":
				$lang = "sv";
				break;
			case "TZ":
				$lang = "sw";
				break;
			case "LK":
				$lang = "ta";
				break;
			case "TJ":
				$lang = "tg";
				break;
			case "TH":
				$lang = "th";
				break;
			case "TM":
				$lang = "tk";
				break;
			case "CY":
			case "TR":
				$lang = "tr";
				break;
			case "PK":
				$lang = "ur";
				break;
			case "UZ":
				$lang = "uz";
				break;
			case "VN":
				$lang = "vi";
				break;
			case "CN":
			case "HK":
			case "TW":
				$lang = "zh";
				break;
			default:
				$lang = self::LANG_DEFAULT;
		}
		if(in_array($lang, self::ALL_ISO_CODES)){
			$this->ipLangCache[$ip] = $lang;
			return $lang;
		}
		$this->ipLangCache[$ip] = $def = self::LANG_DEFAULT;
		return $def;
	}
	
	/**
	 * Applies a rainbow effect to each character in the given string.
	 *
	 * @param string $str The input string to be rainbowized.
	 * @param int $offset A reference offset used for color cycling; defaults to 0.
	 * @return string The rainbowized string.
	 */
	public static function rainbowize(string $str, int &$offset = 0): string{
		if($str === ""){
			return " ";
		}
		$substrings = explode(" ", $str);
		if(count($substrings) > 1){
			$strings = [];
			foreach($substrings as $sub){
				$strings[] = self::rainbowize($sub, $offset);
			}
			return implode(" ", $strings);
		}else{
			$color = 0;
			$msg = "";
			for($i = 0; $i < strlen($str); $i++){
				if($i + $offset !== 0){
					$color = self::$RAINBOW_PATTERN[($i + $offset) % count(self::$RAINBOW_PATTERN)];
				}
				$msg .= $color . $str[$i];
			}
			$offset += $i;
			return $msg;
		}
	}
	
	/**
	 * Retrieves all values of a specified HTML tag from a string.
	 *
	 * @param string $str The input string containing HTML.
	 * @param string $tag The HTML tag to search for.
	 * @return array An array containing all values found within the specified tag.
	 */
	private function getAllHTMLTagValues(string $str, string $tag): array{
		$regex = "#<\s*?" . $tag . "\b[^>]*>(.*?)</" . $tag . "\b[^>]*>#s";
		preg_match_all($regex, $str, $tags);
		return $tags;
	}
	
	/**
	 * Replaces specified HTML tags in the string with their rainbowized version.
	 *
	 * @param string $str The input string containing HTML tags.
	 * @return string The string with replaced HTML tags.
	 * @internal
	 */
	private function replaceHTMLTags(string $str): string{
		if(extension_loaded("xml")){
			$dom = new \DomDocument();
			@$dom->loadHTML($str);
			$elements = $dom->getElementsByTagName("rainbow");
			foreach($elements as $element){
				$str = str_replace("<rainbow>" . $element->nodeValue . "</rainbow>", self::rainbowize($element->nodeValue) . "&r", $str);
			}
		}else{
			$tags = $this->getAllHTMLTagValues($str, "rainbow");
			if(!empty($tags[0]) && !empty($tags[1])){
				for($i = 0; $i < (count($tags) - 1); $i++){
					$str = str_replace($tags[0][$i], self::rainbowize($tags[1][$i]) . "&r", $str);
				}
			}
		}
		return $str;
	}
	
}