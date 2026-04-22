<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    wsUrl: {
        type: String,
        required: true,
    },
    wsToken: {
        type: String,
        required: true,
    },
});

const socket = ref(null);
const connectionState = ref('disconnected');
const statusMessage = ref('Not connected');
const errorMessage = ref('');

const left = ref('');
const right = ref('');
const precision = ref(2);
const operation = ref('add');
const result = ref(null);

const history = ref([]);
const stats = ref({
    total_operations: 0,
    latest_result: null,
    latest_calculated_at: null,
});

const operations = [
    { label: 'Add (+)', value: 'add' },
    { label: 'Subtract (-)', value: 'subtract' },
    { label: 'Multiply (*)', value: 'multiply' },
    { label: 'Divide (/)', value: 'divide' },
    { label: 'Power (^)', value: 'power' },
    { label: 'Modulo (%)', value: 'modulo' },
];

const canSend = computed(() => connectionState.value === 'connected');
const hasHistory = computed(() => history.value.length > 0);

const connect = () => {
    if (!props.wsUrl || !props.wsToken) {
        statusMessage.value = 'WebSocket config missing';
        return;
    }

    if (socket.value && socket.value.readyState === WebSocket.OPEN) {
        socket.value.close();
    }

    const ws = new WebSocket(`${props.wsUrl}?token=${encodeURIComponent(props.wsToken)}`);

    socket.value = ws;
    connectionState.value = 'connecting';
    statusMessage.value = 'Connecting...';

    ws.onopen = () => {
        connectionState.value = 'connected';
        statusMessage.value = 'Connected';
        errorMessage.value = '';
        requestHistory();
        requestStats();
    };

    ws.onclose = () => {
        connectionState.value = 'disconnected';
        statusMessage.value = 'Disconnected';
    };

    ws.onerror = () => {
        statusMessage.value = 'WebSocket error';
    };

    ws.onmessage = (event) => {
        let payload;

        try {
            payload = JSON.parse(event.data);
        } catch {
            errorMessage.value = 'Server returned invalid JSON.';
            return;
        }

        if (payload.type === 'connection.error') {
            errorMessage.value = payload.message;
            return;
        }

        if (payload.type === 'calculation.error') {
            errorMessage.value = payload.message;
            return;
        }

        if (payload.type === 'calculation.result') {
            errorMessage.value = '';
            result.value = payload.entry.result;
            history.value = [payload.entry, ...history.value.filter((item) => item.id !== payload.entry.id)];
            return;
        }

        if (payload.type === 'history.snapshot') {
            history.value = payload.entries;
            return;
        }

        if (payload.type === 'history.cleared') {
            statusMessage.value = payload.message;
            return;
        }

        if (payload.type === 'stats.snapshot') {
            stats.value = payload.stats;
        }
    };
};

const sendPayload = (payload) => {
    if (!canSend.value) {
        errorMessage.value = 'WebSocket not connected.';
        return;
    }

    socket.value.send(JSON.stringify(payload));
};

const requestHistory = () => {
    sendPayload({ action: 'history', limit: 20 });
};

const requestStats = () => {
    sendPayload({ action: 'stats' });
};

const clearHistory = () => {
    errorMessage.value = '';
    sendPayload({ action: 'history.clear' });
};

const calculate = () => {
    errorMessage.value = '';

    sendPayload({
        action: 'calculate',
        operation: operation.value,
        left: left.value,
        right: right.value,
        precision: precision.value,
    });
};

const useEntry = (entry) => {
    left.value = String(entry.left);
    right.value = String(entry.right);
    result.value = entry.result;
};

const formatTimestamp = (timestamp) => {
    if (!timestamp) {
        return '-';
    }

    return new Date(timestamp).toLocaleString();
};

onMounted(() => {
    connect();
});

onBeforeUnmount(() => {
    if (socket.value && socket.value.readyState === WebSocket.OPEN) {
        socket.value.close();
    }
});
</script>

<template>
    <Head title="Real-Time Calculator" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Real-Time Calculator
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Connection</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ statusMessage }}</p>
                    </div>

                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Operations</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.total_operations }}</p>
                    </div>

                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Latest Result</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.latest_result ?? '-' }}</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <input
                            v-model="left"
                            type="text"
                            class="rounded border-gray-300"
                            placeholder="Left operand"
                            @keyup.enter="calculate"
                        >

                        <select v-model="operation" class="rounded border-gray-300">
                            <option v-for="item in operations" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>

                        <input
                            v-model="right"
                            type="text"
                            class="rounded border-gray-300"
                            placeholder="Right operand"
                            @keyup.enter="calculate"
                        >

                        <input
                            v-model.number="precision"
                            type="number"
                            class="rounded border-gray-300"
                            min="0"
                            max="10"
                            step="1"
                            placeholder="Precision (0-10)"
                        >
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="rounded bg-gray-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:bg-gray-400"
                            :disabled="!canSend"
                            @click="calculate"
                        >
                            Calculate
                        </button>

                        <button
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canSend"
                            @click="requestHistory"
                        >
                            Refresh History
                        </button>

                        <button
                            type="button"
                            class="rounded border border-red-300 px-4 py-2 text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canSend || !hasHistory"
                            @click="clearHistory"
                        >
                            Clear History
                        </button>

                        <button
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-gray-700"
                            @click="connect"
                        >
                            Reconnect
                        </button>

                        <p v-if="result !== null" class="text-sm text-gray-800">
                            Result: <span class="font-semibold">{{ result }}</span>
                        </p>
                    </div>

                    <p v-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">History</h3>
                        <p class="text-xs text-gray-500">Last update: {{ formatTimestamp(stats.latest_calculated_at) }}</p>
                    </div>

                    <ul v-if="history.length" class="space-y-2">
                        <li
                            v-for="item in history"
                            :key="item.id"
                            class="flex items-center justify-between rounded border border-gray-200 px-3 py-2 text-sm text-gray-700"
                        >
                            <div>
                                {{ item.left }} {{ item.operation }} {{ item.right }} =
                                <span class="font-semibold">{{ item.result }}</span>
                                <span class="ml-2 text-xs text-gray-400">({{ formatTimestamp(item.calculated_at) }})</span>
                            </div>

                            <button
                                type="button"
                                class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700"
                                @click="useEntry(item)"
                            >
                                Use
                            </button>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-gray-500">No calculations yet.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
