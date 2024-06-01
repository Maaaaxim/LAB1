<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\BotType;
use App\Models\BotUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\Update;

class BotController extends Controller
{
    public function handle($bot, Request $request)
    {

        $botModel = Bot::where('name', $bot)->firstOrFail();
        $telegram = new Api($botModel->token);

        $update = new Update($request->all());

        if ($botModel->type_id === 2) {
            try {
                $this->handelApprovalBot($request, $botModel, $telegram, $update);
            } catch (Exception $e) {
                error_log($e->getMessage());
                return response()->json(['error' => 'Произошла ошибка при обработке вашего запроса'], 500);
            }

        } else {
            if (!$botModel->active) {
                return response()->json(['status' => 'success']);
            }
            if ($update->isType('message')) {

                $message = $update->getMessage();

                $text = $message->getText();
                $from = $message->getFrom();
                $chat = $message->getChat();

                if (str_contains($text, 'start')) {

                    $chatId = $chat->getId();
                    $first_name = $chat->getFirstName();
                    $lastName = $chat->getLastName();
                    $username = $chat->getUsername();
                    $premium = $from->getIsPremium();

                    $imagePath = $botModel->message_image;
                    $messageText = $botModel->message;

                    if ($imagePath) {
                        $absoluteImagePath = Storage::path($imagePath);

                        $photo = InputFile::create($absoluteImagePath, basename($absoluteImagePath));

                        try {
                            $telegram->sendPhoto([
                                'chat_id' => $chatId,
                                'photo' => $photo,
                                'caption' => $messageText
                            ]);
                        } catch (Telegram\Bot\Exceptions\TelegramOtherException $e) {
                            if ($e->getMessage() === 'Forbidden: bot was blocked by the user') {

                                $userModel = BotUser::where('telegram_id', $chatId)->firstOrFail();

                                $userModel->is_banned = 1;
                                $userModel->save();
                            } else {
                                Log::info($e->getMessage());
                            }
                        }
                    } else {
                        $response = $telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => $messageText,
                        ]);
                    }


                    BotUser::addOrUpdateUser($chatId, $first_name, $lastName, $username, $botModel->id, $premium);

                    $userMention = "[{$first_name}](tg://user?id=$chatId)";
                    $adminMessage = $premium ? 'премиум ' : '';
                    $messageText = "Новый {$adminMessage}пользователь: {$userMention}";

                    $botModel->notifyAdmins($messageText);
                }
            } elseif ($update->isType('my_chat_member')) {
                $myChatMember = $update->getMyChatMember();
                $newStatus = $myChatMember['new_chat_member']['status'];

                if ($newStatus === 'kicked') {
                    $userId = $myChatMember['from']['id'];
                    Log::info("Bot was kicked or blocked by user: " . $userId);

                    $userModel = BotUser::where('telegram_id', $userId)->firstOrFail();

                    $userModel->banned_bots()->syncWithoutDetaching([$botModel->id]);
                }
            } else {
                $updateType = $update->detectType();
                Log::info("Received a non-message update of type: " . $updateType);
            }
        }
        return response()->json(['status' => 'success']);
    }

    private function getParam($updates)
    {
        if (isset($updates['message']['text'])) {
            $text = $updates['message']['text'];
            if (preg_match('/\/start (\d+)/', $text, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    private function handelApprovalBot($request, $botModel, $telegram, $update): void
    {
        $updates = json_decode($request->getContent(), true);

        $message = $update->getMessage();
        $text = $message->getText();
        $from = $message->getFrom();
        $premium = $from->getIsPremium();

        if (isset($updates['message'])) {
            $message = $updates['message'];
            $chatId = $message['chat']['id'];
            $first_name = $message['chat']['first_name'];
            $username = $message['chat']['username'];
            $lastName = "";
            BotUser::addOrUpdateUser($chatId, $first_name, $lastName, $username, $botModel->id, $premium);
        } else {
            Log::error('Received update without a message.');
        }

        if (isset($message['contact'])) {
            $contact = $message['contact'];

            $phoneNumber = $contact['phone_number'];
            $firstName = $contact['first_name'];
            $telegram_id = $contact['user_id'];
            $username = $message['from']['username'];

            $user = BotUser::where('telegram_id', $telegram_id)->first();

            $user->phone = $phoneNumber;
            $user->save();


            $userIdFromWordpress = Cache::get($chatId);


            if (!$userIdFromWordpress) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Ошибка, попробуйте перейти по ссылке еще раз!',
                ]);
                return;
            }

            $telegram_id = $chatId;

            $data = [
                'wp_id' => $userIdFromWordpress,
                'tg_id' => $telegram_id,
                'tg_username' => $username,
                'tg_number' => $phoneNumber
            ];

            try {

                $url = $botModel->web_hook;

                $response = Http::asForm()->post($url, $data);

                $body = $response->body();

                if (preg_match('/Wrong Query/', $body)) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Ошибка, попробуйте перейти по ссылке еще раз!',
                    ]);
                } elseif (preg_match('/Number already exists/', $body)) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Ваш номер уже есть в базе!',
                    ]);
                } elseif (preg_match('/Success/', $body)) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Успех!',
                    ]);
                } elseif (preg_match('/User does not exist/', $body)) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Такого пользователя не существует!',
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Error accessing /test.wp: " . $e->getMessage());
            }
        } else {

            $userIdFromWordpress = $this->getParam($updates) ?? '';

            if (!$userIdFromWordpress) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Ошибка, попробуйте перейти по ссылке еще раз!',
                ]);
                return;
            }

            Cache::put($chatId, $userIdFromWordpress, 60);

            $keyboard = Keyboard::make([
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ])->row([
                [
                    'text' => 'Поделиться контактом',
                    'request_contact' => true
                ]
            ]);

            $message = $update->getMessage();
            $chat = $message->getChat();

            if (str_contains($message, '/start')) {

                $chatId = $chat->getId();
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Пожалуйста, поделитесь вашим контактом.',
                    'reply_markup' => $keyboard
                ]);
            }
        }
    }
}

