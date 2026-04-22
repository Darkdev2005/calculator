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
const left = ref('');
const right = ref('');
const operation = ref('add');
const result = ref(null);
const errorMessage = ref('');
const history = ref([]);

const operations = [
    { label: 'Add (+)', value: 'add' },
    { label: 'Subtract (-)', value: 'subtract' },
    { label: 'Multiply (*)', value: 'multiply' },
    { label: 'Divide (/)', value: 'divide' },
];

const canSend = computed(() => connectionState.value === 'connected');

const connect = () => {
    if (!props.wsUrl || !props.wsToken) {
        statusMessage.value = 'WebSocket config missing';
        return;
    }

    const ws = new WebSocket(`${props.wsUrl}?token=${encodeURIComponent(props.wsToken)}`);
    socket.value = ws;
    connectionState.value = 'connecting';
    statusMessage.value = 'Connecting...';

    ws.onopen = () => {
        connectionState.value = 'connected';
        statusMessage.value = 'Connected';
        requestHistory();
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
        }
    };
};

const requestHistory = () => {
    if (!canSend.value) {
        return;
    }

    socket.value.send(JSON.stringify({ action: 'history', limit: 20 }));
};

const calculate = () => {
    errorMessage.value = '';

    if (!canSend.value) {
        errorMessage.value = 'WebSocket not connected.';
        return;
    }

    socket.value.send(JSON.stringify({
        action: 'calculate',
        operation: operation.value,
        left: left.value,
        right: right.value,
    }));
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
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">WebSocket Status: <span class="font-medium text-gray-900">{{ statusMessage }}</span></p>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm space-y-4">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <input v-model="left" type="text" class="rounded border-gray-300" placeholder="Left operand">

                        <select v-model="operation" class="rounded border-gray-300">
                            <option v-for="item in operations" :key="item.value" :value="item.value">
                                {{ item.label }}
                            </option>
                        </select>

                        <input v-model="right" type="text" class="rounded border-gray-300" placeholder="Right operand">
                    </div>

                    <div class="flex items-center gap-4">
                        <button
                            type="button"
                            class="rounded bg-gray-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:bg-gray-400"
                            :disabled="!canSend"
                            @click="calculate"
                        >
                            Calculate
                        </button>

                        <p v-if="result !== null" class="text-sm text-gray-800">Result: <span class="font-semibold">{{ result }}</span></p>
                    </div>

                    <p v-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="mb-3 text-lg font-semibold text-gray-900">History</h3>

                    <ul v-if="history.length" class="space-y-2">
                        <li v-for="item in history" :key="item.id" class="rounded border border-gray-200 px-3 py-2 text-sm text-gray-700">
                            {{ item.left }} {{ item.operation }} {{ item.right }} = <span class="font-semibold">{{ item.result }}</span>
                            <span class="ml-2 text-xs text-gray-400">({{ item.calculated_at }})</span>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-gray-500">No calculations yet.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
