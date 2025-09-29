<?php
declare(strict_types=1);

namespace app\controllers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;
use Yii;
use yii\httpclient\Client;
use yii\web\Controller;

class TelegramBotController extends Controller
{
	public $enableCsrfValidation = false;
	
	private $botToken;
	private $apiUrl;
	private $bearerToken;
	private $csrfToken;
	
	public function init(): void
	{
		parent::init();
		// Environment variables dan o'qish
		$this->botToken    = $_ENV['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN') ?? '';
		$this->bearerToken = $_ENV['HEMIS_BEARER_TOKEN'] ?? getenv('HEMIS_BEARER_TOKEN') ?? '';
		$this->csrfToken   = $_ENV['HEMIS_CSRF_TOKEN'] ?? getenv('HEMIS_CSRF_TOKEN') ?? '';
		$this->apiUrl      = $_ENV['HEMIS_BASE_URL'] ?? getenv('HEMIS_BASE_URL') ?? '';
	}
	
	/**
	 * Webhook uchun action
	 */
	public function actionWebhook()
	{
		try {
			$bot = new BotApi($this->botToken);
			
			$input = file_get_contents('php://input');
			
			// Logga yozish (debug uchun)
			Yii::info('Telegram webhook: '.$input, 'telegram');
			
			$update = Update::fromResponse(json_decode($input, true));
			
			$message = $update->getMessage();
			if (!$message) {
				return;
			}
			
			$chatId   = $message->getChat()->getId();
			$text     = $message->getText();
			$userName = $message->getFrom()->getFirstName();
			
			Yii::info("Message from {$userName} (ID: {$chatId}): {$text}", 'telegram');
			
			if ($text === '/start') {
				$bot->sendMessage(
					$chatId,
					"Assalomu alaykum, {$userName}! 👋\n\n".
					"Men Hemis tizimidan ma'lumotlarni Excel formatida yuklab beraman.\n\n".
					"📊 Buyruqlar:\n".
					"/excel - Ma'lumotlarni Excel formatida olish\n".
					"/help - Yordam"
				);
			} elseif ($text === '/help') {
				$bot->sendMessage(
					$chatId,
					"📖 Yordam:\n\n".
					"/excel - Hemis tizimidan so'nggi 2000 ta log yozuvini Excel faylda yuklab olish\n".
					"/start - Botni qayta ishga tushirish"
				);
			} elseif ($text === '/excel') {
				$startTime = microtime(true);
				
				// Yuklanish xabari
				$bot->sendMessage($chatId, "⏳ Ma'lumotlar yuklanmoqda...");
				
				$data = $this->fetchDataFromApi();
				
				// DEBUG
				Yii::error('Data count: '.(is_array($data) ? count($data) : 'not array'), 'telegram');
				
				if ($data === false) {
					$bot->sendMessage($chatId, "❌ API dan ma'lumot kelmadi.");
					
					return;
				}
				
				if (empty($data)) {
					$bot->sendMessage($chatId, "⚠️ Ma'lumotlar bo'sh.");
					
					return;
				}
				
				// DEBUG
				Yii::error('Creating Excel...', 'telegram');
				
				$filePath = $this->createExcelFile($data);
				
				if ($filePath === false) {
					$bot->sendMessage($chatId, "❌ Excel fayl yaratishda xatolik yuz berdi.");
					
					return;
				}
				
				// Excel faylni yuborish
				try {
					$bot->sendDocument($chatId, new \CURLFile($filePath));
					
					$duration = round(microtime(true) - $startTime, 2);
					$bot->sendMessage(
						$chatId,
						"✅ Ma'lumotlar muvaffaqiyatli yuborildi!\n\n".
						"📁 Jami: ".count($data)." ta yozuv\n".
						"⏱ Vaqt: ".$duration." soniya"
					);
				} catch (\Exception $e) {
					$bot->sendMessage($chatId, "❌ Faylni yuborishda xatolik: ".$e->getMessage());
					Yii::error('File send error: '.$e->getMessage(), 'telegram');
				}
				
				// Faylni o'chirish
				@unlink($filePath);
			} else {
				$bot->sendMessage(
					$chatId,
					"❓ Noma'lum komanda: {$text}\n\n".
					"Mavjud komandalar:\n".
					"/excel - Excel faylni olish\n".
					"/help - Yordam"
				);
			}
			
		} catch (\Exception $e) {
			Yii::error('Telegram bot error: '.$e->getMessage(), 'telegram');
			Yii::error('Stack trace: '.$e->getTraceAsString(), 'telegram');
		}
	}
	
	/**
	 * API dan ma'lumotlarni olish
	 */
	private function fetchDataFromApi()
	{
		try {
			$allData    = [];
			$page       = 1;
			$totalPages = 1;
			
			do {
				$url = $this->apiUrl.'&page='.$page;
				Yii::info("Fetching page {$page}...", 'telegram');
				
				$client   = new Client();
				$response = $client->createRequest()
					->setMethod('GET')
					->setUrl($url)
					->setHeaders([
						'accept'        => 'application/json',
						'Authorization' => 'Bearer '.$this->bearerToken,
						//'Cookie'        => '_csrf='.$this->csrfToken,
					])
					->send();
				
				if (!$response->isOk) {
					Yii::error("Page {$page} request failed", 'telegram');
					break;
				}
				
				$result = $response->data;
				
				if (!isset($result['data']['items'])) {
					break;
				}
				
				// Optimallashtirilgan: har bir elementni alohida qo'shish
				foreach ($result['data']['items'] as $item) {
					$allData[] = $item;
				}
				
				// Pagination ma'lumotlari
				if (isset($result['data']['pagination'])) {
					$pagination = $result['data']['pagination'];
					$totalPages = $pagination['pageCount'] ?? 1;
					
					Yii::info("Page {$page}/{$totalPages} loaded", 'telegram');
				}
				
				$page++;
				
				if ($page > 100) {
					Yii::warning('Max page limit reached', 'telegram');
					break;
				}
				
			} while ($page <= $totalPages);
			
			Yii::info("Total: ".count($allData)." items", 'telegram');
			
			return !empty($allData) ? $allData : false;
			
		} catch (\Exception $e) {
			Yii::error('API error: '.$e->getMessage(), 'telegram');
			
			return false;
		}
	}
	
	/**
	 * Excel fayl yaratish
	 */
	private function createExcelFile($data)
	{
		try {
			$spreadsheet = new Spreadsheet();
			$sheet       = $spreadsheet->getActiveSheet();
			
			if (empty($data)) {
				return false;
			}
			
			// Sarlavhalar
			$headers = ['ID', 'Admin', 'Vaqt', 'Xabar', 'Action', 'Query', 'POST', 'GET', 'IP'];
			$col     = 'A';
			foreach ($headers as $header) {
				$sheet->setCellValue($col.'1', $header);
				$col++;
			}
			
			// Ma'lumotlar
			$row = 2;
			foreach ($data as $item) {
				$sheet->setCellValue('A'.$row, $item['id'] ?? '');
				$sheet->setCellValue('B'.$row, $item['admin_name'] ?? '');
				$sheet->setCellValue('C'.$row, date('Y-m-d H:i:s', $item['created_at'] ?? 0));
				$sheet->setCellValue('D'.$row, $item['message'] ?? '');
				$sheet->setCellValue('E'.$row, $item['action'] ?? '');
				$sheet->setCellValue('F'.$row, $item['query'] ?? '');
				$sheet->setCellValue('G'.$row, json_encode($item['post'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
				$sheet->setCellValue('H'.$row, json_encode($item['get'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
				$sheet->setCellValue('I'.$row, $item['ip'] ?? '');
				$row++;
			}
			
			// Style
			$sheet->getStyle('A1:I1')->getFont()->setBold(true);
			foreach (range('A', 'I') as $col) {
				$sheet->getColumnDimension($col)->setAutoSize(true);
			}
			
			// Saqlash
			$fileName = 'hemis_log_'.date('YmdHis').'.xlsx';
			$filePath = Yii::getAlias('@runtime').'/'.$fileName;
			
			$writer = new Xlsx($spreadsheet);
			$writer->save($filePath);
			
			return $filePath;
			
		} catch (\Exception $e) {
			Yii::error('Excel error: '.$e->getMessage(), 'telegram');
			
			return false;
		}
	}
	
	/**
	 * Webhook o'rnatish uchun action
	 */
	public function actionSetWebhook()
	{
		try {
			$bot = new BotApi($this->botToken);
			
			// Site URL ni aniqlash
			$siteUrl    = Yii::$app->params['siteUrl'] ?? 'http://'.$_SERVER['HTTP_HOST'];
			$webhookUrl = $siteUrl.'/telegram-bot/webhook';
			$result     = $bot->setWebhook($webhookUrl);
			
			if ($result) {
				echo "✅ Webhook muvaffaqiyatli o'rnatildi!\n\n";
				echo "📍 Webhook URL: ".$webhookUrl."\n\n";
				
				// Webhook ma'lumotlarini tekshirish
				$webhookInfo = $bot->getWebhookInfo();
				echo "📊 Webhook info:\n";
				echo "URL: ".$webhookInfo->getUrl()."\n";
				echo "Pending updates: ".$webhookInfo->getPendingUpdateCount()."\n";
				
				if ($webhookInfo->getLastErrorMessage()) {
					echo "⚠️ Last error: ".$webhookInfo->getLastErrorMessage()."\n";
				}
			} else {
				echo "❌ Webhook o'rnatishda xatolik!\n";
			}
		} catch (\Exception $e) {
			echo "❌ Xatolik: ".$e->getMessage()."\n";
		}
	}
	
	/**
	 * Webhook ni o'chirish
	 */
	public function actionDeleteWebhook()
	{
		try {
			$bot    = new BotApi($this->botToken);
			$result = $bot->deleteWebhook();
			
			if ($result) {
				echo "✅ Webhook muvaffaqiyatli o'chirildi!\n";
			} else {
				echo "❌ Webhook o'chirishda xatolik!\n";
			}
		} catch (\Exception $e) {
			echo "❌ Xatolik: ".$e->getMessage()."\n";
		}
	}
	
	/**
	 * Webhook ma'lumotlarini ko'rish
	 */
	public function actionWebhookInfo()
	{
		try {
			$bot  = new BotApi($this->botToken);
			$info = $bot->getWebhookInfo();
			
			echo "📊 Webhook Information:<br/><br/>";
			echo "URL: ".$info->getUrl()."<br/>";
			echo "Pending updates: ".$info->getPendingUpdateCount()."<br/>";
			echo "Max connections: ".$info->getMaxConnections()."<br/>";
			
			if ($info->getLastErrorMessage()) {
				echo "<br/>⚠️ Last error:<br/>";
				echo "Message: ".$info->getLastErrorMessage()."<br/>";
				echo "Date: ".date('Y-m-d H:i:s', $info->getLastErrorDate())."<br/>";
			}
			
			if ($info->getAllowedUpdates()) {
				echo "<br/>Allowed updates: ".implode(', ', $info->getAllowedUpdates())."<br/>";
			}
			
		} catch (\Exception $e) {
			echo "❌ Xatolik: ".$e->getMessage()."<br/>";
		}
	}
}
