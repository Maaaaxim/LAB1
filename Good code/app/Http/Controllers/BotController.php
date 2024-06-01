<?php

namespace App\Http\Controllers;

use App\Http\Resources\BotResource;
use App\Http\Resources\BotTypesCollection;
use App\Models\Bot;
use App\Models\BotType;
use App\Services\TelegramServices\TelegramHandler;
use DateInterval;
use DatePeriod;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BotController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function handle(TelegramHandler $telegramHandler, $bot, Request $request): \Illuminate\Http\JsonResponse
    {
        $telegramHandler->handle($bot, $request);
        return response()->json(['status' => 'success']);
    }
}
