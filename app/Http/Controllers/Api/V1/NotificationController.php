<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Learning\LearningNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        LearningNotifier::remind($r->user());
        $q = DB::table('learning_notifications')->where('recipient_id', $r->user()->id);
        $unread = (clone $q)->whereNull('read_at')->count();
        $rows = $q->orderByDesc('id')->paginate(20);

        return response()->json([...$rows->toArray(), 'unread_count' => $unread]);
    }

    public function read(Request $r): JsonResponse
    {
        $data = $r->validate(['ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer']]);
        $q = DB::table('learning_notifications')->where('recipient_id', $r->user()->id)->whereNull('read_at');
        if (isset($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        }$q->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Notifikasi ditandai sudah dibaca.']);
    }
}
