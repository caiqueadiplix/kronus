<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KronoController extends Controller
{
    private const DEFAULT_TENANT_ID = 1;
    private const STAGES = ['incoming', 'kitchen', 'done'];
    private const CHANNELS = ['whatsapp', 'counter', 'site', 'ifood', 'room'];
    private const TYPES = ['delivery', 'pickup', 'table'];

    public function bootstrap(): JsonResponse
    {
        $orders = DB::table('orders')
            ->where('tenant_id', $this->tenantId())
            ->where('status', '!=', 'cancelled')
            ->orderBy('status')->orderBy('position')->orderByDesc('id')->get()
            ->map(fn ($order) => $this->hydrateOrder($order));

        $commands = DB::table('commands')->where('tenant_id', $this->tenantId())->orderBy('number')->get()
            ->map(fn ($room) => $this->roomSummary('command', $room));
        $tables = DB::table('restaurant_tables')->where('tenant_id', $this->tenantId())->orderBy('number')->get()
            ->map(fn ($room) => $this->roomSummary('table', $room));

        return response()->json([
            'tenant' => DB::table('tenants')->find($this->tenantId()),
            'categories' => DB::table('categories')->where('tenant_id', $this->tenantId())->orderBy('position')->orderBy('id')->get(),
            'sectors' => DB::table('salon_sectors')->where('tenant_id', $this->tenantId())->orderBy('position')->orderBy('id')->get(),
            'products' => DB::table('products')->where('tenant_id', $this->tenantId())->orderBy('category_id')->orderBy('name')->get(),
            'orders' => $orders,
            'commands' => $commands,
            'tables' => $tables,
            'drafts' => DB::table('order_drafts')->where('tenant_id', $this->tenantId())->select('id', 'source', 'customer', 'created_at', 'updated_at')->orderByDesc('updated_at')->get(),
            'drivers' => DB::getSchemaBuilder()->hasTable('delivery_drivers')
                ? DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->orderByDesc('active')->orderBy('name')->get()
                : [],
            'kds_screens' => DB::getSchemaBuilder()->hasTable('kds_screens')
                ? DB::table('kds_screens')->where('tenant_id', $this->tenantId())->orderBy('id')->get()
                : [],
        ]);
    }

    public function stream(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo "event: orders.updated\n";
            echo 'data: '.json_encode(['event' => 'sync', 'at' => now()->toISOString()])."\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function directions(Request $request): JsonResponse
    {
        $address = trim((string) $request->query('address', ''));
        if ($address === '') {
            return $this->error('Endereço não informado.', 422);
        }
        return response()->json([
            'provider' => env('GOOGLE_MAPS_API_KEY') ? 'google-maps' : 'google-maps-link',
            'url' => 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address),
        ]);
    }

    public function autoAccept(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('settings.manage')) return $guard;
        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        $before = DB::table('tenants')->where('id', $this->tenantId())->first();
        DB::table('tenants')->where('id', $this->tenantId())->update(['auto_accept' => $enabled ? 1 : 0]);
        $this->audit('tenant.auto_accept.updated', 'tenant', $this->tenantId(), (array) $before, ['auto_accept' => $enabled]);
        return response()->json(['enabled' => $enabled]);
    }

    public function autoPrint(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('settings.manage')) return $guard;
        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        $before = DB::table('tenants')->where('id', $this->tenantId())->first();
        DB::table('tenants')->where('id', $this->tenantId())->update(['auto_print_orders' => $enabled ? 1 : 0]);
        $this->audit('tenant.auto_print.updated', 'tenant', $this->tenantId(), (array) $before, ['auto_print_orders' => $enabled]);
        return response()->json(['enabled' => $enabled]);
    }

    public function notifications(): JsonResponse
    {
        $items = DB::table('operator_notifications')->where('tenant_id', $this->tenantId())->orderByDesc('id')->limit(30)->get();
        return response()->json([
            'unread' => $items->where('status', 'unread')->count(),
            'items' => $items,
        ]);
    }

    public function createHandoffNotification(Request $request): JsonResponse
    {
        $customer = trim((string) $request->input('customer'));
        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        $externalId = trim((string) $request->input('conversation_id'));
        if ($customer === '' || ! in_array(strlen($phone), [10, 11, 12, 13], true)) {
            return $this->error('Cliente e telefone válido são obrigatórios.', 422);
        }
        if ($externalId !== '' && DB::table('operator_notifications')->where('tenant_id', $this->tenantId())->where('external_id', $externalId)->where('status', 'unread')->exists()) {
            return $this->error('Esta solicitação já está aguardando atendimento.', 409);
        }
        $id = DB::table('operator_notifications')->insertGetId([
            'tenant_id' => $this->tenantId(),
            'type' => 'human_handoff',
            'source' => 'whatsapp',
            'external_id' => $externalId,
            'title' => 'Cliente pediu atendimento humano',
            'message' => trim((string) $request->input('message', 'O robô transferiu esta conversa para a equipe.')),
            'customer' => $customer,
            'phone' => $phone,
            'status' => 'unread',
            'metadata' => json_encode($request->input('metadata', []), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function readNotification(int $id): JsonResponse
    {
        $updated = DB::table('operator_notifications')->where('tenant_id', $this->tenantId())->where('id', $id)->update(['status' => 'read', 'read_at' => now(), 'updated_at' => now()]);
        return $updated ? response()->json(['ok' => true]) : $this->error('Notificação não encontrada.', 404);
    }

    public function readAllNotifications(): JsonResponse
    {
        DB::table('operator_notifications')->where('tenant_id', $this->tenantId())->where('status', 'unread')->update(['status' => 'read', 'read_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function createCategory(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $name = trim((string) $request->input('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) return $this->error('Informe uma categoria entre 2 e 50 caracteres.', 422);
        if (DB::table('categories')->where('tenant_id', $this->tenantId())->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) return $this->error('Esta categoria já existe.', 409);
        $id = DB::table('categories')->insertGetId(['tenant_id' => $this->tenantId(), 'name' => $name, 'position' => ((int) DB::table('categories')->where('tenant_id', $this->tenantId())->max('position')) + 1]);
        $this->audit('category.created', 'category', $id, [], ['name' => $name]);
        return response()->json(['id' => $id, 'name' => $name], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $category = DB::table('categories')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $category) return $this->error('Categoria não encontrada.', 404);
        $name = trim((string) $request->input('name', $category->name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) return $this->error('Nome de categoria inválido.', 422);
        if (DB::table('categories')->where('tenant_id', $this->tenantId())->where('id', '!=', $id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) return $this->error('Esta categoria já existe.', 409);
        DB::table('categories')->where('id', $id)->update(['name' => $name, 'position' => max(0, (int) $request->input('position', $category->position))]);
        $this->audit('category.updated', 'category', $id, (array) $category, ['name' => $name]);
        return response()->json(['ok' => true]);
    }

    public function deleteCategory(int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        if (DB::table('products')->where('tenant_id', $this->tenantId())->where('category_id', $id)->exists()) return $this->error('Mova ou exclua os produtos desta categoria antes de removê-la.', 409);
        $deleted = DB::table('categories')->where('tenant_id', $this->tenantId())->where('id', $id)->delete();
        if ($deleted) $this->audit('category.deleted', 'category', $id, [], []);
        return $deleted ? response()->json(['ok' => true]) : $this->error('Categoria não encontrada.', 404);
    }

    public function createProduct(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $data = $this->validateProduct($request);
        if ($data instanceof JsonResponse) return $data;
        $id = DB::table('products')->insertGetId(['tenant_id' => $this->tenantId()] + $data);
        $this->audit('product.created', 'product', $id, [], $data);
        return response()->json(['id' => $id], 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $product = DB::table('products')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $product) return $this->error('Produto não encontrado.', 404);
        if ($request->keys() === ['active'] || (count($request->keys()) === 1 && $request->has('active'))) {
            $active = filter_var($request->input('active'), FILTER_VALIDATE_BOOL) ? 1 : 0;
            DB::table('products')->where('id', $id)->update(['active' => $active]);
            $this->audit('product.status.updated', 'product', $id, (array) $product, ['active' => $active]);
            return response()->json(['ok' => true]);
        }
        $data = $this->validateProduct($request, $product);
        if ($data instanceof JsonResponse) return $data;
        DB::table('products')->where('id', $id)->update($data);
        $this->audit('product.updated', 'product', $id, (array) $product, $data);
        return response()->json(['ok' => true]);
    }

    public function deleteProduct(int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $used = DB::table('order_items')->where('product_id', $id)->exists();
        if ($used) {
            DB::table('products')->where('tenant_id', $this->tenantId())->where('id', $id)->update(['active' => 0]);
            $this->audit('product.archived', 'product', $id, [], ['active' => 0]);
            return response()->json(['ok' => true, 'archived' => true]);
        }
        $deleted = DB::table('products')->where('tenant_id', $this->tenantId())->where('id', $id)->delete();
        if ($deleted) $this->audit('product.deleted', 'product', $id, [], []);
        return $deleted ? response()->json(['ok' => true, 'archived' => false]) : $this->error('Produto não encontrado.', 404);
    }

    public function uploadProductImage(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('menu.manage')) return $guard;
        $product = DB::table('products')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $product) return $this->error('Produto não encontrado.', 404);
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) return $this->error('Selecione uma imagem válida.', 422);
        $file = $request->file('image');
        if (! in_array(strtolower($file->extension()), ['jpg', 'jpeg', 'png', 'webp'], true)) return $this->error('Use JPG, PNG ou WEBP.', 422);
        if ($file->getSize() > 4 * 1024 * 1024) return $this->error('A imagem deve ter no máximo 4 MB.', 422);
        $path = $file->store('products/'.$this->tenantId(), 'public');
        $url = Storage::disk('public')->url($path);
        DB::table('products')->where('id', $id)->update(['image_url' => $url]);
        $this->audit('product.image.updated', 'product', $id, ['image_url' => $product->image_url], ['image_url' => $url]);
        return response()->json(['url' => $url]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->all();
        $customer = trim((string) ($data['customer'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $channel = (string) ($data['channel'] ?? 'counter');
        $type = (string) ($data['type'] ?? 'pickup');
        $address = trim((string) ($data['address'] ?? ''));
        $fee = filter_var($data['fee_cents'] ?? 0, FILTER_VALIDATE_INT);
        $serviceFee = 0;
        $discount = filter_var($data['discount_cents'] ?? 0, FILTER_VALIDATE_INT);
        $document = preg_replace('/\D/', '', (string) ($data['document'] ?? ''));
        $commandNumber = isset($data['command_number']) && $data['command_number'] !== '' ? (int) $data['command_number'] : null;
        $tableNumber = isset($data['table_number']) && $data['table_number'] !== '' ? (int) $data['table_number'] : null;

        if ($customer === '' || ! is_array($data['items'] ?? null) || count($data['items']) === 0) {
            return $this->error('Cliente e ao menos um item são obrigatórios.', 422);
        }
        if (! in_array($channel, self::CHANNELS, true) || ! in_array($type, self::TYPES, true)) {
            return $this->error('Canal ou tipo de pedido inválido.', 422);
        }
        $addressFields = $this->normalizeAddress($data, $type, $address);
        if ($addressFields instanceof JsonResponse) return $addressFields;
        $address = $addressFields['address'];
        if ($phone !== '' && ! in_array(strlen(preg_replace('/\D/', '', $phone)), [10, 11, 12, 13], true)) {
            return $this->error('Telefone inválido.', 422);
        }
        if ($type === 'delivery' && mb_strlen($address) < 5) {
            return $this->error('Informe o endereço completo da entrega.', 422);
        }
        if ($fee === false || $fee < 0 || $discount === false || $discount < 0) {
            return $this->error('Taxa ou desconto inválido.', 422);
        }
        if ($document !== '' && ! in_array(strlen($document), [11, 14], true)) {
            return $this->error('CPF/CNPJ deve possuir 11 ou 14 dígitos.', 422);
        }

        $roomKind = $tableNumber ? 'table' : ($commandNumber ? 'command' : null);
        if ($type === 'table' && ! $roomKind) {
            return $this->error('Selecione uma mesa ou comanda.', 422);
        }
        if ($roomKind && $type !== 'table') {
            return $this->error('Mesa ou comanda só pode ser vinculada a pedido de salão.', 422);
        }
        $room = $roomKind ? $this->findRoom($roomKind, $tableNumber ?: $commandNumber) : null;
        if ($roomKind && ! $room) {
            return $this->error($this->roomConfig($roomKind)['label'].' não encontrada.', 404);
        }
        if ($room?->status === 'closing') {
            return $this->error($this->roomConfig($roomKind)['label'].' está fechando a conta.', 409);
        }

        try {
            $items = $this->normalizeItems($data['items']);
        } catch (Throwable $error) {
            return $this->error($error->getMessage(), 422);
        }

        $subtotal = array_reduce($items, fn (int $sum, array $item) => $sum + $item['quantity'] * $item['unit_price_cents'], 0);
        if ($roomKind) {
            $tenantSettings = DB::table('tenants')->where('id', $this->tenantId())->first();
            if ((bool) ($tenantSettings->service_fee_enabled ?? false)) {
                $serviceFee = (int) round($subtotal * (float) ($tenantSettings->service_fee_percent ?? 0) / 100);
            }
        }
        $total = max(0, $subtotal + $fee + $serviceFee - $discount);
        if ($discount > $subtotal + $fee + $serviceFee) {
            return $this->error('O desconto não pode ser maior que o total.', 422);
        }

        $payment = is_array($data['payment'] ?? null) ? $data['payment'] : null;
        if ($payment) {
            $method = (string) ($payment['method'] ?? '');
            $paidAmount = filter_var($payment['paid_amount_cents'] ?? null, FILTER_VALIDATE_INT);
            if (! in_array($method, ['pix', 'card', 'cash'], true) || $paidAmount === false || $paidAmount < $total) {
                return $this->error('Pagamento inválido ou insuficiente.', 422);
            }
        }

        try {
            $result = DB::transaction(function () use ($data, $customer, $phone, $channel, $type, $address, $addressFields, $fee, $serviceFee, $discount, $document, $commandNumber, $tableNumber, $roomKind, $room, $items, $payment, $total): array {
                $tenant = DB::table('tenants')->find($this->tenantId());
                $confirmed = ($data['confirmed'] ?? false) === true;
                $automatic = ! $confirmed && (bool) ($tenant->auto_accept ?? false) && ! in_array($channel, ['counter', 'room'], true);
                $sentToKitchen = $confirmed || $automatic;
                $status = $sentToKitchen ? 'kitchen' : 'incoming';
                $acceptedAt = $sentToKitchen || in_array($channel, ['counter', 'room'], true) ? now()->toISOString() : null;
                $sessionId = $roomKind ? ($room->session_id ?: (string) Str::uuid()) : '';
                $externalId = trim((string) ($data['external_id'] ?? ''));

                if ($externalId !== '' && DB::table('orders')->where('tenant_id', $this->tenantId())->where('external_id', $externalId)->exists()) {
                    throw new \RuntimeException('Este pedido externo já foi recebido.');
                }

                $orderId = DB::table('orders')->insertGetId([
                    'tenant_id' => $this->tenantId(),
                    'customer' => $customer,
                    'phone' => $phone,
                    'channel' => $channel,
                    'type' => $type,
                    'status' => $status,
                    'address' => $address,
                    'postal_code' => $addressFields['postal_code'],
                    'street' => $addressFields['street'],
                    'address_number' => $addressFields['number'],
                    'address_complement' => $addressFields['complement'],
                    'neighborhood' => $addressFields['neighborhood'],
                    'city' => $addressFields['city'],
                    'state' => $addressFields['state'],
                    'fee_cents' => $fee,
                    'service_fee_cents' => $serviceFee,
                    'discount_cents' => $discount,
                    'table_number' => $tableNumber,
                    'command_number' => $commandNumber,
                    'accepted_at' => $acceptedAt,
                    'payment_status' => $payment ? 'paid' : 'pending',
                    'payment_method' => $payment['method'] ?? '',
                    'paid_amount_cents' => $payment ? (int) $payment['paid_amount_cents'] : 0,
                    'paid_at' => $payment ? now()->toISOString() : null,
                    'updated_at' => now()->toISOString(),
                    'position' => $this->nextPosition($status),
                    'external_id' => $externalId,
                    'notes' => trim((string) ($data['notes'] ?? '')),
                    'document' => $document,
                    'room_session_id' => $sessionId,
                ]);

                foreach ($items as $item) {
                    DB::table('order_items')->insert(['order_id' => $orderId] + $item);
                }

                if ($roomKind) {
                    $config = $this->roomConfig($roomKind);
                    DB::table($config['table'])->where('tenant_id', $this->tenantId())->where('number', $tableNumber ?: $commandNumber)->update([
                        'status' => 'busy', 'customer' => $customer, 'session_id' => $sessionId, 'updated_at' => now()->toISOString(),
                    ]);
                }

                $this->event($orderId, $sentToKitchen ? 'created_and_sent_to_kitchen' : 'created_pending');
                if ($payment) {
                    $this->event($orderId, 'payment_paid_'.$payment['method'], (string) $payment['paid_amount_cents']);
                }

                return ['id' => $orderId, 'status' => $status, 'total_cents' => $total];
            });
        } catch (Throwable $error) {
            return $this->error($error->getMessage(), str_contains($error->getMessage(), 'já foi recebido') ? 409 : 500);
        }

        return response()->json($result, 201);
    }

    public function acceptOrder(int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->status === 'cancelled') return $this->error('Pedido não encontrado.', 404);
        if ($order->status !== 'incoming') return $this->error('Somente pedidos na Entrada podem ser aceitos.', 409);
        if (! $order->accepted_at) {
            DB::table('orders')->where('id', $id)->where('tenant_id', $this->tenantId())->update(['accepted_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
            $this->event($id, 'accepted_manually');
        }
        return response()->json(['ok' => true]);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order) return $this->error('Pedido não encontrado.', 404);
        $next = (string) $request->input('status');
        $currentIndex = array_search($order->status, self::STAGES, true);
        $nextIndex = array_search($next, self::STAGES, true);
        if ($currentIndex === false || $nextIndex === false || $nextIndex - $currentIndex !== 1) return $this->error('O pedido só pode avançar para a próxima etapa.', 409);
        if ($next === 'kitchen' && ! $order->accepted_at) return $this->error('Aceite o pedido antes de enviá-lo à cozinha.', 409);
        $updates = ['status' => $next, 'position' => $this->nextPosition($next), 'updated_at' => now()->toISOString()];
        if ($next === 'done' && $order->type === 'delivery' && empty($order->delivery_status)) $updates['delivery_status'] = 'awaiting_driver';
        DB::table('orders')->where('id', $id)->where('tenant_id', $this->tenantId())->update($updates);
        $this->event($id, 'status_changed', $order->status.'->'.$next);
        return response()->json(['ok' => true]);
    }

    public function updateOrder(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->status === 'cancelled') return $this->error('Pedido não encontrado.', 404);
        if ($order->status !== 'incoming') return $this->error('Somente pedidos na Entrada podem ser editados.', 409);
        $data = $request->all();
        $customer = trim((string) ($data['customer'] ?? ''));
        $type = (string) ($data['type'] ?? '');
        $channel = (string) ($data['channel'] ?? '');
        $address = trim((string) ($data['address'] ?? ''));
        $fee = filter_var($data['fee_cents'] ?? 0, FILTER_VALIDATE_INT);
        $serviceFee = (int) ($order->service_fee_cents ?? 0);
        $discount = filter_var($data['discount_cents'] ?? 0, FILTER_VALIDATE_INT);
        $phone = trim((string) ($data['phone'] ?? ''));
        $document = preg_replace('/\D/', '', (string) ($data['document'] ?? ''));
        $addressFields = $this->normalizeAddress($data, $type, $address);
        if ($addressFields instanceof JsonResponse) return $addressFields;
        $address = $addressFields['address'];
        if ($customer === '' || ! in_array($type, self::TYPES, true) || ! in_array($channel, self::CHANNELS, true) || $fee === false || $fee < 0 || $discount === false || $discount < 0) return $this->error('Dados do pedido inválidos.', 422);
        if ($type === 'delivery' && mb_strlen($address) < 5) return $this->error('Endereço é obrigatório para delivery.', 422);
        if ($phone !== '' && ! in_array(strlen(preg_replace('/\D/', '', $phone)), [10, 11, 12, 13], true)) return $this->error('Telefone inválido.', 422);
        if ($document !== '' && ! in_array(strlen($document), [11, 14], true)) return $this->error('CPF/CNPJ deve possuir 11 ou 14 dígitos.', 422);
        try { $items = $this->normalizeItems($data['items'] ?? []); } catch (Throwable $error) { return $this->error($error->getMessage(), 422); }
        $subtotal = array_reduce($items, fn (int $sum, array $item) => $sum + $item['quantity'] * $item['unit_price_cents'], 0);
        if ($discount > $subtotal + $fee + $serviceFee) return $this->error('O desconto não pode ser maior que o total.', 422);

        DB::transaction(function () use ($id, $data, $customer, $phone, $document, $type, $channel, $address, $addressFields, $fee, $serviceFee, $discount, $items): void {
            DB::table('orders')->where('id', $id)->where('tenant_id', $this->tenantId())->update([
                'customer' => $customer, 'phone' => $phone, 'channel' => $channel, 'type' => $type,
                'address' => $address, 'postal_code' => $addressFields['postal_code'], 'street' => $addressFields['street'],
                'address_number' => $addressFields['number'], 'address_complement' => $addressFields['complement'],
                'neighborhood' => $addressFields['neighborhood'], 'city' => $addressFields['city'], 'state' => $addressFields['state'],
                'fee_cents' => $fee, 'service_fee_cents' => $serviceFee, 'discount_cents' => $discount, 'notes' => trim((string) ($data['notes'] ?? '')),
                'document' => $document, 'updated_at' => now()->toISOString(),
            ]);
            DB::table('order_items')->where('order_id', $id)->delete();
            foreach ($items as $item) DB::table('order_items')->insert(['order_id' => $id] + $item);
            $this->event($id, 'edited');
        });
        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->status === 'cancelled') return $this->error('Pedido não encontrado.', 404);
        $targetId = $request->input('target_id');
        $target = $targetId ? $this->findOrder((int) $targetId) : null;
        if ($targetId && (! $target || $target->status !== $order->status || $target->id === $order->id)) return $this->error('Destino de ordenação inválido.', 409);
        $siblings = DB::table('orders')->where('tenant_id', $this->tenantId())->where('status', $order->status)->orderBy('position')->orderByDesc('id')->pluck('id')->filter(fn ($value) => (int) $value !== $id)->values()->all();
        $index = $target ? array_search($target->id, $siblings, true) : count($siblings);
        array_splice($siblings, $index === false ? count($siblings) : $index, 0, [$id]);
        DB::transaction(function () use ($siblings): void { foreach ($siblings as $position => $orderId) DB::table('orders')->where('id', $orderId)->update(['position' => $position, 'updated_at' => now()->toISOString()]); });
        $this->event($id, 'reordered', $target ? 'before:'.$target->id : 'last');
        return response()->json(['ok' => true]);
    }

    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->status === 'cancelled') return $this->error('Pedido não encontrado.', 404);
        if ($order->status === 'done') return $this->error('Pedido finalizado não pode ser cancelado.', 409);
        DB::table('orders')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
        if (! empty($order->driver_id) && DB::getSchemaBuilder()->hasTable('delivery_drivers')) {
            DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $order->driver_id)->update(['status' => 'available', 'current_order_id' => null, 'updated_at' => now()]);
        }
        $this->event($id, 'cancelled', trim((string) $request->input('reason', 'Cancelado pelo operador')));
        $this->releaseEmptyRoom($order);
        return response()->json(['ok' => true]);
    }

    public function payment(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->status === 'cancelled') return $this->error('Pedido não encontrado.', 404);
        if ($request->input('status') !== 'paid') {
            DB::table('orders')->where('id', $id)->update(['payment_status' => 'pending', 'payment_method' => '', 'paid_amount_cents' => 0, 'paid_at' => null, 'updated_at' => now()->toISOString()]);
            $this->event($id, 'payment_pending');
            return response()->json(['ok' => true]);
        }
        $method = (string) $request->input('method');
        $amount = filter_var($request->input('paid_amount_cents'), FILTER_VALIDATE_INT);
        $total = $this->orderTotal($order);
        if (! in_array($method, ['pix', 'card', 'cash'], true) || $amount === false || $amount < $total) return $this->error('Forma de pagamento ou valor recebido inválido.', 422);
        DB::table('orders')->where('id', $id)->update(['payment_status' => 'paid', 'payment_method' => $method, 'paid_amount_cents' => $amount, 'paid_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
        $this->event($id, 'payment_paid_'.$method, (string) $amount);
        return response()->json(['ok' => true, 'change_cents' => max(0, $amount - $total)]);
    }

    public function waiter(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $order = $this->findOrder($id);
        $waiterId = (int) $request->input('waiter_id');
        if (! $order || ! $order->table_number) return $this->error('Pedido de mesa não encontrado.', 404);
        $member = DB::table('tenant_users')->where('tenant_id', $this->tenantId())->where('user_id', $waiterId)->where('role', 'waiter')->first();
        if (! $member) return $this->error('O usuário selecionado não é um garçom deste tenant.', 422);
        DB::table('orders')->where('id', $id)->update(['waiter_id' => $waiterId, 'updated_at' => now()->toISOString()]);
        $this->event($id, 'waiter_assigned', (string) $waiterId);
        return response()->json(['ok' => true, 'waiter_id' => $waiterId]);
    }

    public function driver(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order) return $this->error('Pedido não encontrado.', 404);
        if ($order->type !== 'delivery' || ($order->delivery_status ?? '') === 'delivered') return $this->error('Este delivery não aceita nova atribuição.', 409);
        $driverId = (int) $request->input('driver_id');
        $driver = $driverId
            ? DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $driverId)->where('active', 1)->first()
            : null;
        $driverName = trim((string) ($driver->name ?? $request->input('driver')));
        if (mb_strlen($driverName) < 2) return $this->error('Selecione um entregador disponível.', 422);

        DB::transaction(function () use ($order, $id, $driver, $driverName): void {
            if (! empty($order->driver_id)) {
                DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $order->driver_id)
                    ->update(['status' => 'available', 'current_order_id' => null, 'updated_at' => now()]);
            }
            if ($driver) {
                if ($driver->status === 'offline') throw new \RuntimeException('O entregador selecionado está offline.');
                if ($driver->current_order_id && (int) $driver->current_order_id !== $id) throw new \RuntimeException('O entregador já está em outra rota.');
                DB::table('delivery_drivers')->where('id', $driver->id)->update(['status' => 'busy', 'current_order_id' => $id, 'updated_at' => now()]);
            }
            DB::table('orders')->where('id', $id)->update([
                'driver_id' => $driver?->id,
                'driver_name' => $driverName,
                'delivery_status' => 'assigned',
                'updated_at' => now()->toISOString(),
            ]);
            $this->event($id, 'driver_assigned', $driverName);
        });
        return response()->json(['ok' => true, 'driver_id' => $driver?->id, 'driver_name' => $driverName, 'delivery_status' => 'assigned']);
    }

    public function deliveryStatus(Request $request, int $id): JsonResponse
    {
        $order = $this->findOrder($id);
        if (! $order || $order->type !== 'delivery') return $this->error('Delivery não encontrado.', 404);
        $target = (string) $request->input('status');
        $current = (string) ($order->delivery_status ?: (! empty($order->driver_name) ? 'assigned' : 'awaiting_driver'));
        $allowed = [
            'awaiting_driver' => ['assigned'],
            'assigned' => ['picked_up', 'awaiting_driver'],
            'picked_up' => ['delivered'],
            'delivered' => [],
        ];
        if (! in_array($target, $allowed[$current] ?? [], true)) return $this->error('Transição de entrega inválida.', 409);
        if ($target === 'picked_up' && $order->status !== 'done') return $this->error('O pedido precisa estar pronto antes de sair para entrega.', 409);

        DB::transaction(function () use ($order, $id, $target): void {
            $updates = ['delivery_status' => $target, 'updated_at' => now()->toISOString()];
            if ($target === 'awaiting_driver') {
                $updates += ['driver_id' => null, 'driver_name' => ''];
            }
            if ($target === 'delivered') $updates['delivered_at'] = now()->toISOString();
            DB::table('orders')->where('id', $id)->update($updates);
            if (! empty($order->driver_id)) {
                DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $order->driver_id)->update([
                    'status' => in_array($target, ['delivered', 'awaiting_driver'], true) ? 'available' : 'busy',
                    'current_order_id' => in_array($target, ['delivered', 'awaiting_driver'], true) ? null : $id,
                    'updated_at' => now(),
                ]);
            }
            $this->event($id, 'delivery_'.$target);
        });
        return response()->json(['ok' => true, 'status' => $target]);
    }

    public function drivers(): JsonResponse
    {
        return response()->json(['items' => DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->orderByDesc('active')->orderBy('name')->get()]);
    }

    public function createDriver(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $payload = $this->validateDriver($request);
        if ($payload instanceof JsonResponse) return $payload;
        $id = DB::table('delivery_drivers')->insertGetId($payload + ['tenant_id' => $this->tenantId(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('driver.created', 'delivery_driver', $id, [], $payload);
        return response()->json(['id' => $id], 201);
    }

    public function updateDriver(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $current = DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $current) return $this->error('Entregador não encontrado.', 404);
        $payload = $this->validateDriver($request, $current);
        if ($payload instanceof JsonResponse) return $payload;
        if ($current->current_order_id && ($payload['active'] === 0 || $payload['status'] === 'offline')) return $this->error('Finalize a rota atual antes de desativar o entregador.', 409);
        DB::table('delivery_drivers')->where('id', $id)->update($payload + ['updated_at' => now()]);
        $this->audit('driver.updated', 'delivery_driver', $id, (array) $current, $payload);
        return response()->json(['ok' => true]);
    }

    public function deleteDriver(int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $driver = DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $driver) return $this->error('Entregador não encontrado.', 404);
        if ($driver->current_order_id) return $this->error('Entregador em rota não pode ser excluído.', 409);
        DB::table('delivery_drivers')->where('id', $id)->delete();
        $this->audit('driver.deleted', 'delivery_driver', $id, (array) $driver);
        return response()->json(['ok' => true]);
    }

    public function kdsScreens(): JsonResponse
    {
        return response()->json(['items' => DB::table('kds_screens')->where('tenant_id', $this->tenantId())->orderBy('id')->get()]);
    }

    public function createKdsScreen(Request $request): JsonResponse
    {
        $payload = $this->validateKdsScreen($request);
        if ($payload instanceof JsonResponse) return $payload;
        $id = DB::table('kds_screens')->insertGetId($payload + ['tenant_id' => $this->tenantId(), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id], 201);
    }

    public function updateKdsScreen(Request $request, int $id): JsonResponse
    {
        $current = DB::table('kds_screens')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $current) return $this->error('Tela KDS não encontrada.', 404);
        $payload = $this->validateKdsScreen($request, $current);
        if ($payload instanceof JsonResponse) return $payload;
        DB::table('kds_screens')->where('id', $id)->update($payload + ['updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function deleteKdsScreen(int $id): JsonResponse
    {
        $screen = DB::table('kds_screens')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $screen) return $this->error('Tela KDS não encontrada.', 404);
        if (DB::table('kds_screens')->where('tenant_id', $this->tenantId())->count() <= 1) return $this->error('Mantenha ao menos uma tela KDS.', 409);
        DB::table('kds_screens')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $orders = DB::table('orders')->where('tenant_id', $this->tenantId())->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())->get();
        $ids = $orders->pluck('id');
        $itemsTotal = $ids->isEmpty() ? 0 : (int) DB::table('order_items')->whereIn('order_id', $ids)->selectRaw('COALESCE(SUM(quantity * unit_price_cents), 0) AS total')->value('total');
        $fees = (int) $orders->sum(fn ($order) => (int) $order->fee_cents + (int) ($order->service_fee_cents ?? 0) - (int) ($order->discount_cents ?? 0));
        $total = max(0, $itemsTotal + $fees);
        $byChannel = $orders->groupBy('channel')->map(fn ($group) => $group->count());
        $byStatus = $orders->groupBy('status')->map(fn ($group) => $group->count());
        return response()->json([
            'period_days' => $days,
            'orders' => $orders->count(),
            'revenue_cents' => $total,
            'average_ticket_cents' => $orders->count() ? (int) round($total / $orders->count()) : 0,
            'paid_orders' => $orders->where('payment_status', 'paid')->count(),
            'by_channel' => $byChannel,
            'by_status' => $byStatus,
            'low_stock' => DB::table('products')->where('tenant_id', $this->tenantId())->where('stock_enabled', 1)->whereColumn('stock_quantity', '<=', 'stock_minimum')->count(),
        ]);
    }

    public function createCommand(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $number = (int) ($request->input('number') ?: ((int) DB::table('commands')->where('tenant_id', $this->tenantId())->max('number') + 1));
        if ($number < 1 || $number > 99999) return $this->error('Número de comanda inválido.', 422);
        if (DB::table('commands')->where('tenant_id', $this->tenantId())->where('number', $number)->exists()) return $this->error('Já existe uma comanda com esse número.', 409);
        $id = DB::table('commands')->insertGetId(['tenant_id' => $this->tenantId(), 'number' => $number, 'status' => 'free', 'customer' => '', 'session_id' => '', 'updated_at' => now()->toISOString()]);
        return response()->json(['id' => $id, 'number' => $number], 201);
    }

    public function createSector(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $name = trim((string) $request->input('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) return $this->error('Informe um setor entre 2 e 60 caracteres.', 422);
        if (DB::table('salon_sectors')->where('tenant_id', $this->tenantId())->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) return $this->error('Este setor já existe.', 409);
        $id = DB::table('salon_sectors')->insertGetId(['tenant_id' => $this->tenantId(), 'name' => $name, 'position' => ((int) DB::table('salon_sectors')->where('tenant_id', $this->tenantId())->max('position')) + 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('salon.sector.created', 'salon_sector', $id, [], ['name' => $name]);
        return response()->json(['id' => $id, 'name' => $name], 201);
    }

    public function salonHistory(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        return response()->json(['items' => DB::table('salon_movements')->where('tenant_id', $this->tenantId())->orderByDesc('id')->limit($limit)->get()]);
    }

    public function createTable(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $number = (int) ($request->input('number') ?: ((int) DB::table('restaurant_tables')->where('tenant_id', $this->tenantId())->max('number') + 1));
        $name = trim((string) $request->input('name', 'Mesa '.$number));
        if ($number < 1 || $number > 9999 || mb_strlen($name) < 3 || mb_strlen($name) > 30) return $this->error('Número ou nome de mesa inválido.', 422);
        if (DB::table('restaurant_tables')->where('tenant_id', $this->tenantId())->where('number', $number)->exists()) return $this->error('Já existe uma mesa com esse número.', 409);
        $sectorId = $request->input('sector_id') ? (int) $request->input('sector_id') : null;
        if ($sectorId && ! DB::table('salon_sectors')->where('tenant_id', $this->tenantId())->where('id', $sectorId)->exists()) return $this->error('Setor inválido.', 422);
        $id = DB::table('restaurant_tables')->insertGetId(['tenant_id' => $this->tenantId(), 'number' => $number, 'name' => $name, 'status' => 'free', 'customer' => '', 'session_id' => '', 'sector_id' => $sectorId, 'seats' => max(1, min(50, (int) $request->input('seats', 4))), 'position_x' => max(1, min(100, (int) $request->input('position_x', $number))), 'position_y' => max(1, min(100, (int) $request->input('position_y', 1))), 'shape' => in_array($request->input('shape'), ['square', 'round', 'rectangle'], true) ? $request->input('shape') : 'square', 'qr_token' => (string) Str::uuid(), 'updated_at' => now()->toISOString()]);
        $this->audit('table.created', 'restaurant_table', $id, [], ['number' => $number, 'name' => $name]);
        return response()->json(['id' => $id, 'number' => $number, 'name' => $name], 201);
    }

    public function updateTable(Request $request, int $number): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $table = DB::table('restaurant_tables')->where('tenant_id', $this->tenantId())->where('number', $number)->first();
        if (! $table) return $this->error('Mesa não encontrada.', 404);
        $name = trim((string) $request->input('name', $table->name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 30) return $this->error('O nome deve possuir entre 3 e 30 caracteres.', 422);
        $sectorId = $request->has('sector_id') && $request->input('sector_id') !== '' ? (int) $request->input('sector_id') : $table->sector_id;
        if ($sectorId && ! DB::table('salon_sectors')->where('tenant_id', $this->tenantId())->where('id', $sectorId)->exists()) return $this->error('Setor inválido.', 422);
        $changes = ['name' => $name, 'sector_id' => $sectorId, 'seats' => max(1, min(50, (int) $request->input('seats', $table->seats))), 'position_x' => max(1, min(100, (int) $request->input('position_x', $table->position_x))), 'position_y' => max(1, min(100, (int) $request->input('position_y', $table->position_y))), 'shape' => in_array($request->input('shape', $table->shape), ['square', 'round', 'rectangle'], true) ? $request->input('shape', $table->shape) : 'square', 'updated_at' => now()->toISOString()];
        DB::table('restaurant_tables')->where('tenant_id', $this->tenantId())->where('number', $number)->update($changes);
        $this->audit('table.updated', 'restaurant_table', $table->id, (array) $table, $changes);
        return response()->json(['ok' => true] + $changes);
    }

    public function roomDetail(string $kind, int $number): JsonResponse
    {
        $room = $this->findRoom($kind, $number);
        if (! $room) return $this->error('Mesa ou comanda não encontrada.', 404);
        return response()->json($this->roomSummary($kind, $room));
    }

    public function deleteRoom(string $kind, int $number): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $room = $this->findRoom($kind, $number);
        if (! $room) return $this->error('Mesa ou comanda não encontrada.', 404);
        if ($room->status !== 'free' || $room->session_id !== '') return $this->error('Somente registros livres podem ser excluídos.', 409);
        DB::table($this->roomConfig($kind)['table'])->where('tenant_id', $this->tenantId())->where('number', $number)->delete();
        return response()->json(['ok' => true]);
    }

    public function transferRoom(Request $request, string $kind, int $number): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $source = $this->findRoom($kind, $number);
        $targetKind = (string) $request->input('target_kind');
        $targetNumber = (int) $request->input('target_number');
        if (! $source || ! in_array($targetKind, ['table', 'command'], true)) return $this->error('Origem ou destino inválido.', 422);
        $target = $this->findRoom($targetKind, $targetNumber);
        if (! $target || ($kind === $targetKind && $number === $targetNumber)) return $this->error('Destino inválido.', 422);
        if ($source->status === 'free' || $source->session_id === '') return $this->error('A origem não possui conta aberta.', 409);
        if ($target->status === 'closing') return $this->error('O destino está fechando a conta.', 409);
        $targetSession = $target->session_id ?: (string) Str::uuid();
        $sourceConfig = $this->roomConfig($kind); $targetConfig = $this->roomConfig($targetKind);

        DB::transaction(function () use ($source, $target, $number, $targetNumber, $targetSession, $sourceConfig, $targetConfig): void {
            $orders = DB::table('orders')->where('tenant_id', $this->tenantId())->where($sourceConfig['foreign'], $number)->where('room_session_id', $source->session_id)->where('status', '!=', 'cancelled');
            $orders->update([
                'table_number' => $targetConfig['foreign'] === 'table_number' ? $targetNumber : null,
                'command_number' => $targetConfig['foreign'] === 'command_number' ? $targetNumber : null,
                'room_session_id' => $targetSession,
                'updated_at' => now()->toISOString(),
            ]);
            DB::table($sourceConfig['table'])->where('tenant_id', $this->tenantId())->where('number', $number)->update(['status' => 'free', 'customer' => '', 'session_id' => '', 'updated_at' => now()->toISOString()]);
            DB::table($targetConfig['table'])->where('tenant_id', $this->tenantId())->where('number', $targetNumber)->update(['status' => 'busy', 'customer' => $target->customer ?: $source->customer, 'session_id' => $targetSession, 'updated_at' => now()->toISOString()]);
        });
        return response()->json(['ok' => true]);
    }

    public function closeRoom(Request $request, string $kind, int $number): JsonResponse
    {
        if ($guard = $this->requirePermission('salon.manage')) return $guard;
        $room = $this->findRoom($kind, $number);
        if (! $room || $room->status === 'free' || $room->session_id === '') return $this->error('A conta não está aberta.', 409);
        $summary = $this->roomSummary($kind, $room);
        if ($summary['has_active_orders']) return $this->error('Finalize todos os pedidos antes de fechar a conta.', 409);
        $method = (string) $request->input('method');
        $amount = filter_var($request->input('paid_amount_cents', 0), FILTER_VALIDATE_INT);
        if ($summary['balance_cents'] > 0 && (! in_array($method, ['pix', 'card', 'cash'], true) || $amount === false || $amount < $summary['balance_cents'])) return $this->error('Informe um pagamento suficiente para fechar a conta.', 422);
        $config = $this->roomConfig($kind);

        DB::transaction(function () use ($summary, $method, $config, $number): void {
            foreach ($summary['orders'] as $order) {
                if ($order->payment_status !== 'paid') {
                    $total = $this->orderTotal($order);
                    DB::table('orders')->where('id', $order->id)->update(['payment_status' => 'paid', 'payment_method' => $method, 'paid_amount_cents' => $total, 'paid_at' => now()->toISOString(), 'updated_at' => now()->toISOString()]);
                    $this->event($order->id, 'payment_paid_'.$method, (string) $total);
                }
                $this->event($order->id, 'room_closed', $config['label'].' '.$number);
            }
            DB::table($config['table'])->where('tenant_id', $this->tenantId())->where('number', $number)->update(['status' => 'free', 'customer' => '', 'session_id' => '', 'updated_at' => now()->toISOString()]);
        });
        return response()->json(['ok' => true, 'change_cents' => max(0, (int) $amount - $summary['balance_cents'])]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('orders.manage')) return $guard;
        $payload = $request->input('payload');
        if (! is_array($payload) || ! is_array($payload['items'] ?? null) || count($payload['items']) === 0) return $this->error('Adicione ao menos um item ao rascunho.', 422);
        $source = (string) $request->input('source', 'counter');
        if (! in_array($source, ['counter', 'drawer', 'room'], true)) return $this->error('Origem de rascunho inválida.', 422);
        $id = DB::table('order_drafts')->insertGetId(['tenant_id' => $this->tenantId(), 'source' => $source, 'customer' => trim((string) ($payload['customer'] ?? '')), 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id], 201);
    }

    public function draft(int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('orders.manage')) return $guard;
        $draft = DB::table('order_drafts')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        if (! $draft) return $this->error('Rascunho não encontrado.', 404);
        return response()->json(['id' => $draft->id, 'source' => $draft->source, 'payload' => json_decode($draft->payload, true), 'created_at' => $draft->created_at, 'updated_at' => $draft->updated_at]);
    }

    public function deleteDraft(int $id): JsonResponse
    {
        if ($guard = $this->requirePermission('orders.manage')) return $guard;
        $deleted = DB::table('order_drafts')->where('tenant_id', $this->tenantId())->where('id', $id)->delete();
        return $deleted ? response()->json(['ok' => true]) : $this->error('Rascunho não encontrado.', 404);
    }

    private function validateDriver(Request $request, ?object $current = null): array|JsonResponse
    {
        $name = trim((string) $request->input('name', $current->name ?? ''));
        $phone = preg_replace('/\D/', '', (string) $request->input('phone', $current->phone ?? ''));
        $vehicle = (string) $request->input('vehicle_type', $current->vehicle_type ?? 'motorcycle');
        $plate = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $request->input('plate', $current->plate ?? '')));
        $status = (string) $request->input('status', $current->status ?? 'available');
        $active = filter_var($request->input('active', $current->active ?? true), FILTER_VALIDATE_BOOL);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) return $this->error('Informe o nome do entregador.', 422);
        if ($phone !== '' && ! in_array(strlen($phone), [10, 11, 12, 13], true)) return $this->error('Informe um WhatsApp válido.', 422);
        if (! in_array($vehicle, ['motorcycle', 'bicycle', 'car', 'walking'], true)) return $this->error('Tipo de veículo inválido.', 422);
        if (strlen($plate) > 8 || ! in_array($status, ['available', 'busy', 'offline'], true)) return $this->error('Placa ou status inválido.', 422);
        if ($phone !== '' && DB::table('delivery_drivers')->where('tenant_id', $this->tenantId())->where('phone', $phone)->when($current, fn ($query) => $query->where('id', '!=', $current->id))->exists()) return $this->error('Já existe um entregador com este WhatsApp.', 409);
        return ['name' => $name, 'phone' => $phone, 'vehicle_type' => $vehicle, 'plate' => $plate, 'status' => $active ? $status : 'offline', 'active' => $active ? 1 : 0];
    }

    private function validateKdsScreen(Request $request, ?object $current = null): array|JsonResponse
    {
        $name = trim((string) $request->input('name', $current->name ?? ''));
        $station = (string) $request->input('station', $current->station ?? 'kitchen');
        $categories = $request->input('categories', null);
        if ($categories === null && $current) $categories = json_decode((string) $current->categories_json, true);
        if (! is_array($categories)) $categories = [];
        $categories = array_values(array_unique(array_filter(array_map('intval', $categories), fn ($id) => $id > 0)));
        $active = filter_var($request->input('active', $current->active ?? true), FILTER_VALIDATE_BOOL);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) return $this->error('Informe um nome de tela entre 2 e 80 caracteres.', 422);
        if (! in_array($station, ['kitchen', 'grill', 'drinks', 'expedition'], true)) return $this->error('Estação KDS inválida.', 422);
        if ($categories && DB::table('categories')->where('tenant_id', $this->tenantId())->whereIn('id', $categories)->count() !== count($categories)) return $this->error('Uma das categorias selecionadas é inválida.', 422);
        return ['name' => $name, 'station' => $station, 'categories_json' => json_encode($categories), 'active' => $active ? 1 : 0];
    }

    private function normalizeAddress(array $data, string $type, string $fallback): array|JsonResponse
    {
        $empty = ['address' => $type === 'pickup' ? 'Retirada no balcão' : $fallback, 'postal_code' => '', 'street' => '', 'number' => '', 'complement' => '', 'neighborhood' => '', 'city' => '', 'state' => ''];
        if ($type !== 'delivery') return $empty;
        if (! is_array($data['address_fields'] ?? null)) {
            if (mb_strlen($fallback) < 5) return $this->error('Informe o endereço completo da entrega.', 422);
            $empty['address'] = $fallback;
            return $empty;
        }
        $fields = $data['address_fields'];
        $postalCode = preg_replace('/\D/', '', (string) ($fields['postal_code'] ?? ''));
        $street = trim((string) ($fields['street'] ?? ''));
        $number = trim((string) ($fields['number'] ?? ''));
        $complement = trim((string) ($fields['complement'] ?? ''));
        $neighborhood = trim((string) ($fields['neighborhood'] ?? ''));
        $city = trim((string) ($fields['city'] ?? ''));
        $state = strtoupper(trim((string) ($fields['state'] ?? '')));
        if (strlen($postalCode) !== 8) return $this->error('CEP deve possuir 8 dígitos.', 422);
        if ($street === '' || $number === '' || $neighborhood === '' || $city === '' || ! preg_match('/^[A-Z]{2}$/', $state)) return $this->error('Preencha rua, número, bairro, cidade e UF.', 422);
        $parts = ["{$street}, {$number}", $complement, $neighborhood, "{$city}/{$state}", substr($postalCode, 0, 5).'-'.substr($postalCode, 5)];
        return ['address' => implode(' · ', array_values(array_filter($parts))), 'postal_code' => $postalCode, 'street' => $street, 'number' => $number, 'complement' => $complement, 'neighborhood' => $neighborhood, 'city' => $city, 'state' => $state];
    }

    private function validateProduct(Request $request, ?object $current = null): array|JsonResponse
    {
        $name = trim((string) $request->input('name', $current->name ?? ''));
        $description = trim((string) $request->input('description', $current->description ?? ''));
        $categoryId = (int) $request->input('category_id', $current->category_id ?? 0);
        $price = filter_var($request->input('price_cents', $current->price_cents ?? null), FILTER_VALIDATE_INT);
        $cost = filter_var($request->input('cost_price_cents', $current->cost_price_cents ?? 0), FILTER_VALIDATE_INT);
        $prep = filter_var($request->input('prep_time_minutes', $current->prep_time_minutes ?? 0), FILTER_VALIDATE_INT);
        $stock = filter_var($request->input('stock_quantity', $current->stock_quantity ?? 0), FILTER_VALIDATE_INT);
        $minimum = filter_var($request->input('stock_minimum', $current->stock_minimum ?? 0), FILTER_VALIDATE_INT);
        $active = filter_var($request->input('active', $current->active ?? true), FILTER_VALIDATE_BOOL);
        $activePdv = filter_var($request->input('active_pdv', $current->active_pdv ?? true), FILTER_VALIDATE_BOOL);
        $activeDelivery = filter_var($request->input('active_delivery', $current->active_delivery ?? true), FILTER_VALIDATE_BOOL);
        $activeSite = filter_var($request->input('active_site', $current->active_site ?? true), FILTER_VALIDATE_BOOL);
        $allowNotes = filter_var($request->input('allow_notes', $current->allow_notes ?? true), FILTER_VALIDATE_BOOL);
        $stockEnabled = filter_var($request->input('stock_enabled', $current->stock_enabled ?? false), FILTER_VALIDATE_BOOL);
        $sku = trim((string) $request->input('sku', $current->sku ?? ''));
        $barcode = trim((string) $request->input('barcode', $current->barcode ?? ''));
        $imageUrl = trim((string) $request->input('image_url', $current->image_url ?? ''));
        $options = $request->input('options', null);
        if ($options === null && $current) $options = json_decode((string) ($current->options_json ?? '[]'), true);
        if (! is_array($options)) $options = [];
        $options = array_values(array_filter(array_map(function ($option): ?array {
            if (! is_array($option)) return null;
            $label = trim((string) ($option['label'] ?? ''));
            $value = filter_var($option['value_cents'] ?? 0, FILTER_VALIDATE_INT);
            if ($label === '' || $value === false || $value < 0) return null;
            return ['label' => mb_substr($label, 0, 80), 'value_cents' => $value];
        }, $options)));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) return $this->error('Informe um nome de produto entre 2 e 100 caracteres.', 422);
        if (mb_strlen($description) > 500) return $this->error('A descrição deve possuir no máximo 500 caracteres.', 422);
        if ($price === false || $price < 0 || $price > 100000000 || $cost === false || $cost < 0 || $prep === false || $prep < 0 || $prep > 1440 || $stock === false || $stock < 0 || $minimum === false || $minimum < 0) return $this->error('Preço, estoque ou tempo de preparo inválido.', 422);
        if (mb_strlen($sku) > 64 || mb_strlen($barcode) > 64 || ($imageUrl !== '' && ! filter_var($imageUrl, FILTER_VALIDATE_URL))) return $this->error('SKU, código de barras ou imagem inválidos.', 422);
        if (! DB::table('categories')->where('tenant_id', $this->tenantId())->where('id', $categoryId)->exists()) return $this->error('Categoria inválida.', 422);
        if ($sku !== '' && DB::table('products')->where('tenant_id', $this->tenantId())->where('sku', $sku)->when($current, fn ($query) => $query->where('id', '!=', $current->id))->exists()) return $this->error('Este SKU já está em uso neste tenant.', 409);
        return ['category_id' => $categoryId, 'name' => $name, 'description' => $description, 'price_cents' => $price, 'active' => $active ? 1 : 0, 'sku' => $sku, 'barcode' => $barcode, 'image_url' => $imageUrl, 'cost_price_cents' => $cost, 'prep_time_minutes' => $prep, 'active_pdv' => $activePdv ? 1 : 0, 'active_delivery' => $activeDelivery ? 1 : 0, 'active_site' => $activeSite ? 1 : 0, 'allow_notes' => $allowNotes ? 1 : 0, 'stock_enabled' => $stockEnabled ? 1 : 0, 'stock_quantity' => $stock, 'stock_minimum' => $minimum, 'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE)];
    }

    private function normalizeItems(array $items): array
    {
        if (count($items) === 0) throw new \RuntimeException('O pedido deve possuir ao menos um item.');
        return array_map(function (array $item): array {
            $productId = ! empty($item['product_id']) ? (int) $item['product_id'] : null;
            $product = $productId ? DB::table('products')->where('tenant_id', $this->tenantId())->where('id', $productId)->where('active', 1)->first() : null;
            if ($productId && ! $product) throw new \RuntimeException('Um dos produtos não está disponível.');
            $name = trim((string) ($item['name'] ?? $product->name ?? ''));
            $quantity = filter_var($item['quantity'] ?? 0, FILTER_VALIDATE_INT);
            $price = $product ? (int) $product->price_cents : filter_var($item['unit_price_cents'] ?? null, FILTER_VALIDATE_INT);
            $notes = trim((string) ($item['notes'] ?? ''));
            $selectedOptions = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($product && $selectedOptions) {
                $allowed = json_decode((string) ($product->options_json ?? '[]'), true);
                $allowed = is_array($allowed) ? $allowed : [];
                $allowedByLabel = collect($allowed)->keyBy(fn ($option) => mb_strtolower(trim((string) ($option['label'] ?? ''))));
                $labels = [];
                foreach ($selectedOptions as $selected) {
                    $label = mb_strtolower(trim((string) ($selected['label'] ?? $selected)));
                    $option = $allowedByLabel->get($label);
                    if (! $option) throw new \RuntimeException('Um dos complementos não está disponível.');
                    $price += (int) ($option['value_cents'] ?? 0);
                    $labels[] = (string) $option['label'];
                }
                if ($labels) $name .= ' · '.implode(', ', $labels);
            }
            if ($name === '' || $quantity === false || $quantity < 1 || $quantity > 99 || $price === false || $price < 0) throw new \RuntimeException('Há itens inválidos no pedido.');
            return ['product_id' => $productId, 'name' => $name, 'quantity' => $quantity, 'unit_price_cents' => $price, 'notes' => $notes];
        }, $items);
    }

    private function hydrateOrder(object $order): object
    {
        $order->items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        return $order;
    }

    private function findOrder(int $id): ?object
    {
        return DB::table('orders')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
    }

    private function nextPosition(string $status): int
    {
        return ((int) DB::table('orders')->where('tenant_id', $this->tenantId())->where('status', $status)->max('position')) + 1;
    }

    private function orderTotal(object $order): int
    {
        $subtotal = (int) DB::table('order_items')->where('order_id', $order->id)->selectRaw('COALESCE(SUM(quantity * unit_price_cents), 0) AS total')->value('total');
        return max(0, $subtotal + (int) ($order->fee_cents ?? 0) + (int) ($order->service_fee_cents ?? 0) - (int) ($order->discount_cents ?? 0));
    }

    private function event(int $orderId, string $event, string $details = ''): void
    {
        DB::table('order_events')->insert(['order_id' => $orderId, 'tenant_id' => $this->tenantId(), 'event' => $event, 'details' => $details, 'created_at' => now()]);
    }

    private function roomConfig(string $kind): array
    {
        return $kind === 'table'
            ? ['table' => 'restaurant_tables', 'foreign' => 'table_number', 'label' => 'Mesa']
            : ['table' => 'commands', 'foreign' => 'command_number', 'label' => 'Comanda'];
    }

    private function findRoom(string $kind, int $number): ?object
    {
        if (! in_array($kind, ['table', 'command'], true)) return null;
        return DB::table($this->roomConfig($kind)['table'])->where('tenant_id', $this->tenantId())->where('number', $number)->first();
    }

    private function roomOrders(string $kind, object $room)
    {
        if ($room->session_id === '') return collect();
        $config = $this->roomConfig($kind);
        return DB::table('orders')->where('tenant_id', $this->tenantId())->where($config['foreign'], $room->number)->where('room_session_id', $room->session_id)->where('status', '!=', 'cancelled')->orderByDesc('id')->get()->map(fn ($order) => $this->hydrateOrder($order));
    }

    private function roomSummary(string $kind, object $room): array
    {
        $orders = $this->roomOrders($kind, $room);
        $total = $orders->sum(fn ($order) => $this->orderTotal($order));
        $paid = $orders->filter(fn ($order) => $order->payment_status === 'paid')->sum(fn ($order) => $this->orderTotal($order));
        return array_merge((array) $room, ['orders' => $orders, 'total_cents' => $total, 'paid_cents' => $paid, 'balance_cents' => max(0, $total - $paid), 'has_active_orders' => $orders->contains(fn ($order) => ! in_array($order->status, ['done', 'cancelled'], true))]);
    }

    private function releaseEmptyRoom(object $order): void
    {
        $kind = $order->table_number ? 'table' : ($order->command_number ? 'command' : null);
        if (! $kind || ! $order->room_session_id) return;
        $config = $this->roomConfig($kind);
        $number = $kind === 'table' ? $order->table_number : $order->command_number;
        $remaining = DB::table('orders')->where('tenant_id', $this->tenantId())->where($config['foreign'], $number)->where('room_session_id', $order->room_session_id)->where('status', '!=', 'cancelled')->exists();
        if (! $remaining) DB::table($config['table'])->where('tenant_id', $this->tenantId())->where('number', $number)->update(['status' => 'free', 'customer' => '', 'session_id' => '', 'updated_at' => now()->toISOString()]);
    }

    private function tenantId(): int
    {
        $slug = trim((string) (request()->header('X-Krono-Tenant') ?: request()->query('tenant') ?: request()->route('tenantSlug')));
        $query = DB::table('tenants')->where('status', 'active');
        if ($slug !== '') $query->where('slug', $slug);
        $tenant = $query->orderBy('id')->first();
        if (! $tenant && $slug !== '') abort(404, 'Tenant não encontrado.');
        if (! $tenant) $tenant = DB::table('tenants')->where('id', self::DEFAULT_TENANT_ID)->first();
        if (! $tenant) abort(500, 'Tenant padrão não configurado.');

        if (auth()->check()) {
            $membership = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', auth()->id())->first();
            if (! $membership && auth()->user()->email !== env('KRONO_OWNER_EMAIL')) abort(403, 'Usuário sem acesso a este tenant.');
        }
        return (int) $tenant->id;
    }

    private function currentRole(): string
    {
        if (! auth()->check()) return env('KRONO_REQUIRE_AUTH', false) ? 'guest' : 'owner';
        if (auth()->user()->email === env('KRONO_OWNER_EMAIL')) return 'owner';
        return (string) (DB::table('tenant_users')->where('tenant_id', $this->tenantId())->where('user_id', auth()->id())->value('role') ?: 'viewer');
    }

    private function requirePermission(string $permission): ?JsonResponse
    {
        if (env('KRONO_REQUIRE_AUTH', false) && ! auth()->check()) return $this->error('Autenticação obrigatória para esta ação.', 401);
        $role = $this->currentRole();
        $permissions = [
            'owner' => ['*'],
            'admin' => ['*'],
            'manager' => ['orders.manage', 'menu.manage', 'salon.manage', 'settings.manage', 'audit.read'],
            'operator' => ['orders.manage', 'salon.manage'],
            'kitchen' => ['orders.manage'],
            'viewer' => ['audit.read'],
            'waiter' => ['orders.manage', 'salon.manage'],
        ];
        if (in_array('*', $permissions[$role] ?? [], true) || in_array($permission, $permissions[$role] ?? [], true)) return null;
        return $this->error('Seu papel não permite esta ação.', 403);
    }

    private function audit(string $action, string $entityType, int|string|null $entityId = null, array $before = [], array $after = [], array $metadata = []): void
    {
        if (! DB::getSchemaBuilder()->hasTable('audit_logs')) return;
        DB::table('audit_logs')->insert([
            'tenant_id' => $this->tenantId(),
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'ip_address' => request()->ip() ?: '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('audit.read')) return $guard;
        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        return response()->json(['items' => DB::table('audit_logs')->where('tenant_id', $this->tenantId())->orderByDesc('id')->limit($limit)->get()]);
    }

    public function createTenant(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('tenant.manage')) return $guard;
        $name = trim((string) $request->input('name'));
        $slug = Str::slug((string) ($request->input('slug') ?: $name));
        if (mb_strlen($name) < 2 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) return $this->error('Nome ou slug inválido.', 422);
        if (DB::table('tenants')->where('slug', $slug)->exists()) return $this->error('Este slug já está em uso.', 409);
        $id = DB::table('tenants')->insertGetId(['name' => $name, 'public_name' => $name, 'slug' => $slug, 'status' => 'active', 'created_at' => now()]);
        $this->audit('tenant.created', 'tenant', $id, [], ['name' => $name, 'slug' => $slug]);
        if (auth()->check()) DB::table('tenant_users')->insertOrIgnore(['tenant_id' => $id, 'user_id' => auth()->id(), 'role' => 'owner', 'permissions_json' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id, 'name' => $name, 'slug' => $slug], 201);
    }

    public function members(): JsonResponse
    {
        if ($guard = $this->requirePermission('tenant.manage')) return $guard;
        $items = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_users.tenant_id', $this->tenantId())->select('tenant_users.id', 'tenant_users.user_id', 'users.name', 'users.email', 'tenant_users.phone', 'tenant_users.active', 'tenant_users.role', 'tenant_users.permissions_json', 'tenant_users.created_at')->orderBy('users.name')->get();
        return response()->json(['items' => $items]);
    }

    public function upsertMember(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('tenant.manage')) return $guard;
        $name = trim((string) $request->input('name'));
        $email = strtolower(trim((string) $request->input('email')));
        $phone = preg_replace('/\D/', '', (string) $request->input('phone', ''));
        $role = (string) $request->input('role', 'waiter');
        if (mb_strlen($name) < 2 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['owner', 'admin', 'manager', 'operator', 'kitchen', 'waiter', 'viewer'], true)) return $this->error('Nome, e-mail ou papel inválido.', 422);
        if ($phone !== '' && ! in_array(strlen($phone), [10, 11, 12, 13], true)) return $this->error('WhatsApp inválido.', 422);
        $temporaryPassword = null;
        $user = DB::table('users')->where('email', $email)->first();
        if (! $user) {
            $temporaryPassword = Str::random(10);
            $userId = DB::table('users')->insertGetId(['name' => $name, 'email' => $email, 'password' => Hash::make($temporaryPassword), 'created_at' => now(), 'updated_at' => now()]);
            $user = DB::table('users')->where('id', $userId)->first();
        } else {
            DB::table('users')->where('id', $user->id)->update(['name' => $name, 'updated_at' => now()]);
        }
        DB::table('tenant_users')->updateOrInsert(['tenant_id' => $this->tenantId(), 'user_id' => $user->id], ['role' => $role, 'phone' => $phone, 'active' => 1, 'permissions_json' => json_encode($request->input('permissions', []), JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]);
        $this->audit('tenant.member.updated', 'tenant_user', $user->id, [], ['email' => $email, 'role' => $role, 'phone' => $phone]);
        return response()->json(['ok' => true, 'user_id' => $user->id, 'temporary_password' => $temporaryPassword]);
    }

    public function toggleMember(int $userId): JsonResponse
    {
        if ($guard = $this->requirePermission('tenant.manage')) return $guard;
        $member = DB::table('tenant_users')->where('tenant_id', $this->tenantId())->where('user_id', $userId)->first();
        if (! $member) return $this->error('Garçom não encontrado.', 404);
        $active = ! (bool) $member->active;
        DB::table('tenant_users')->where('tenant_id', $this->tenantId())->where('user_id', $userId)->update(['active' => $active, 'updated_at' => now()]);
        $this->audit('tenant.member.status_updated', 'tenant_user', $userId, ['active' => (bool) $member->active], ['active' => $active]);
        return response()->json(['ok' => true, 'active' => $active]);
    }

    public function serviceFee(): JsonResponse
    {
        $tenant = DB::table('tenants')->find($this->tenantId());
        return response()->json(['enabled' => (bool) ($tenant->service_fee_enabled ?? false), 'percent' => (float) ($tenant->service_fee_percent ?? 10)]);
    }

    public function updateServiceFee(Request $request): JsonResponse
    {
        if ($guard = $this->requirePermission('settings.manage')) return $guard;
        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        $percent = (float) str_replace(',', '.', (string) $request->input('percent', 10));
        if ($percent < 0 || $percent > 30) return $this->error('A taxa deve ficar entre 0% e 30%.', 422);
        $before = DB::table('tenants')->find($this->tenantId());
        DB::table('tenants')->where('id', $this->tenantId())->update(['service_fee_enabled' => $enabled ? 1 : 0, 'service_fee_percent' => $percent]);
        $this->audit('tenant.service_fee.updated', 'tenant', $this->tenantId(), (array) $before, ['enabled' => $enabled, 'percent' => $percent]);
        return response()->json(['enabled' => $enabled, 'percent' => $percent]);
    }

    public function removeMember(int $userId): JsonResponse
    {
        if ($guard = $this->requirePermission('tenant.manage')) return $guard;
        $deleted = DB::table('tenant_users')->where('tenant_id', $this->tenantId())->where('user_id', $userId)->delete();
        if ($deleted) $this->audit('tenant.member.removed', 'tenant_user', $userId);
        return $deleted ? response()->json(['ok' => true]) : $this->error('Membro não encontrado.', 404);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
