<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function ask(Request $request)
    {
        $response = Http::post('http://127.0.0.1:8001/chat', [
            'question' => $request->input('question'),
        ]);

        return response()->json($response->json());
    }
}
