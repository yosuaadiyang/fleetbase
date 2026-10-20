<?php
require getenv('CONTRUST_TEST_AUTOLOAD') ?: '/fleetbase/api/vendor/autoload.php';
if (!class_exists(App\Support\ChatPagination::class)) require getenv('CONTRUST_TEST_PAGINATION_SOURCE') ?: '/fleetbase/api/app/Support/ChatPagination.php';
use App\Support\ChatPagination;
use Illuminate\Http\Request;
$checks = 0;
function checkPage(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
foreach ([1, '1', 37, '100'] as $limit) {
    checkPage(ChatPagination::options(new Request(['limit' => $limit]), 'channels')['limit'] === (int) $limit, 'bounded valid limit');
}
foreach ([0, -1, 101, '100000000000000000000000000', 'bad', '', null, true, [], [1]] as $limit) {
    try { ChatPagination::options(new Request(['limit' => $limit]), 'channels'); throw new RuntimeException('invalid limit accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
checkPage(ChatPagination::options(new Request(), 'members:all', 500)['limit'] === 500, 'legacy directory default');
foreach ([null, '2026-10-20 10:00:00', '2026-10-20 10:00:00.123456'] as $stamp) {
    $cursor = ChatPagination::cursor('messages:chat_qa', $stamp, 'message_qa');
    checkPage(ChatPagination::options(new Request(['cursor' => $cursor]), 'messages:chat_qa')['after'] === [$stamp, 'message_qa'], 'timestamp or legacy null roundtrip');
}
$name = 'QA Nama anggota';
$cursor = ChatPagination::cursor('members:all', $name, 'user_qa');
checkPage(ChatPagination::options(new Request(['cursor' => $cursor]), 'members:all')['after'] === [$name, 'user_qa'], 'directory cursor is not parsed as a timestamp');
$bad = ['bad', '', str_repeat('a', 2049), ['array'], true,
    ChatPagination::cursor('channels', '2026-10-20 10:00:00', 'chat_qa'),
    ChatPagination::cursor('messages:chat_qa', "2026-10-20\0 10:00:00", 'message_qa'),
    ChatPagination::cursor('messages:chat_qa', '2026-99-99 10:00:00', 'message_qa'),
    ChatPagination::cursor('messages:chat_qa', '2026-10-20 99:00:00', 'message_qa'),
    ChatPagination::cursor('messages:chat_qa', 'today', 'message_qa'),
    ChatPagination::cursor('messages:chat_qa', '2026-10-20 10:00:00', 'bad/id'),
    ChatPagination::cursor('messages:chat_qa', '2026-10-20 10:00:00', str_repeat('x', 192)),
];
foreach ($bad as $cursor) {
    try { ChatPagination::options(new Request(['cursor' => $cursor]), 'messages:chat_qa'); throw new RuntimeException('invalid cursor accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
echo json_encode(['checks' => $checks, 'passed' => $checks, 'failed' => 0, 'database_used' => false, 'network_used' => false]), PHP_EOL;
