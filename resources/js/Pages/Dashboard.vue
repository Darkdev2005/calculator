<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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

const sections = ref([]);
const selectedSectionId = ref(null);
const newSectionName = ref('');

const left = ref('');
const right = ref('');
const precision = ref(2);
const note = ref('');
const operation = ref('add');
const result = ref(null);

const chainMode = ref(true);
const chainSteps = ref([]);

const history = ref([]);
const stats = ref({
    section: null,
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
const activeSection = computed(() => sections.value.find((section) => section.id === selectedSectionId.value) ?? null);
const chainPreview = computed(() => (chainSteps.value.length ? chainSteps.value.join('  ->  ') : 'Hali zanjirli hisob yo\'q.'));

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
        requestSections();
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

        if (payload.type === 'sections.snapshot') {
            sections.value = payload.sections ?? [];

            if (!sections.value.length) {
                selectedSectionId.value = null;
                history.value = [];
                return;
            }

            const hasSelected = sections.value.some((section) => section.id === selectedSectionId.value);

            if (!hasSelected) {
                selectedSectionId.value = payload.default_section_id ?? sections.value[0].id;
            }

            return;
        }

        if (payload.type === 'section.created') {
            statusMessage.value = `Section created: ${payload.section.name}`;
            newSectionName.value = '';
            return;
        }

        if (payload.type === 'calculation.result') {
            const entrySectionId = payload.entry?.section?.id ?? null;

            if (entrySectionId !== selectedSectionId.value) {
                return;
            }

            errorMessage.value = '';
            result.value = payload.entry.result;
            history.value = [payload.entry, ...history.value.filter((item) => item.id !== payload.entry.id)];

            const step = `${payload.entry.left} ${payload.entry.operation} ${payload.entry.right} = ${payload.entry.result}`;
            chainSteps.value = [...chainSteps.value, step].slice(-12);

            if (chainMode.value) {
                left.value = String(payload.entry.result);
                right.value = '';
                note.value = '';
            }

            return;
        }

        if (payload.type === 'history.snapshot') {
            const snapshotSectionId = payload.section?.id ?? null;

            if (snapshotSectionId !== selectedSectionId.value) {
                return;
            }

            history.value = payload.entries;
            return;
        }

        if (payload.type === 'history.cleared') {
            const sectionId = payload.section?.id ?? null;

            if (sectionId === selectedSectionId.value) {
                statusMessage.value = payload.message;
                chainSteps.value = [];
                result.value = null;
            }

            return;
        }

        if (payload.type === 'stats.snapshot') {
            const sectionId = payload.stats?.section?.id ?? null;

            if (sectionId !== selectedSectionId.value) {
                return;
            }

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

const requestSections = () => {
    sendPayload({ action: 'sections.list' });
};

const requestHistory = () => {
    if (!selectedSectionId.value) {
        return;
    }

    sendPayload({ action: 'history', section_id: selectedSectionId.value, limit: 20 });
};

const requestStats = () => {
    if (!selectedSectionId.value) {
        return;
    }

    sendPayload({ action: 'stats', section_id: selectedSectionId.value });
};

const createSection = () => {
    errorMessage.value = '';

    sendPayload({ action: 'sections.create', name: newSectionName.value });
};

const clearHistory = () => {
    errorMessage.value = '';

    sendPayload({ action: 'history.clear', section_id: selectedSectionId.value });
};

const calculate = () => {
    errorMessage.value = '';

    if (!selectedSectionId.value) {
        errorMessage.value = 'Avval bo\'lim tanlang.';
        return;
    }

    sendPayload({
        action: 'calculate',
        operation: operation.value,
        left: left.value,
        right: right.value,
        precision: precision.value,
        note: note.value,
        section_id: selectedSectionId.value,
    });
};

const useEntry = (entry) => {
    left.value = String(entry.result);
    right.value = '';
    result.value = entry.result;
    note.value = '';
    errorMessage.value = '';
};

const continueWithResult = () => {
    if (result.value === null) {
        return;
    }

    left.value = String(result.value);
    right.value = '';
    errorMessage.value = '';
};

const formatTimestamp = (timestamp) => {
    if (!timestamp) {
        return '-';
    }

    return new Date(timestamp).toLocaleString();
};

watch(selectedSectionId, (newSectionId, oldSectionId) => {
    if (!newSectionId || newSectionId === oldSectionId) {
        return;
    }

    result.value = null;
    errorMessage.value = '';
    chainSteps.value = [];
    requestHistory();
    requestStats();
});

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
                        <p class="text-xs uppercase tracking-wide text-gray-500">Active Section</p>
                        <p class="mt-1 text-lg font-semibold text-gray-900">{{ activeSection?.name ?? '-' }}</p>
                    </div>

                    <div class="rounded-lg bg-white p-4 shadow-sm">
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Operations</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats.total_operations }}</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm space-y-4">
                    <h3 class="text-lg font-semibold text-gray-900">Bo'limlar</h3>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <select v-model.number="selectedSectionId" class="rounded border-gray-300" :disabled="!canSend">
                            <option v-for="section in sections" :key="section.id" :value="section.id">
                                {{ section.name }}
                            </option>
                        </select>

                        <input
                            v-model="newSectionName"
                            type="text"
                            class="rounded border-gray-300"
                            placeholder="Yangi bo'lim nomi"
                            maxlength="120"
                            @keyup.enter="createSection"
                        >

                        <button
                            type="button"
                            class="rounded bg-gray-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:bg-gray-400"
                            :disabled="!canSend"
                            @click="createSection"
                        >
                            Bo'lim yaratish
                        </button>
                    </div>
                </div>

                <form class="rounded-lg bg-white p-6 shadow-sm space-y-4" @submit.prevent="calculate">
                    <h3 class="text-lg font-semibold text-gray-900">Hisob-kitob</h3>

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
                            @keyup.enter="calculate"
                        >
                    </div>

                    <textarea
                        v-model="note"
                        class="w-full rounded border-gray-300"
                        rows="3"
                        maxlength="255"
                        placeholder="Zametka (masalan: Bugungi bozor harajati)"
                        @keydown.enter.exact.prevent="calculate"
                    />

                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        <div class="flex flex-wrap items-center gap-4">
                            <label class="inline-flex items-center gap-2">
                                <input v-model="chainMode" type="checkbox" class="rounded border-gray-300">
                                Natijani keyingi hisobga ulash
                            </label>

                            <button
                                type="button"
                                class="rounded border border-gray-300 px-3 py-1 text-xs text-gray-700 disabled:opacity-50"
                                :disabled="result === null"
                                @click="continueWithResult"
                            >
                                Natijani davom ettir
                            </button>
                        </div>

                        <p class="mt-2 text-xs text-gray-500">{{ chainPreview }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="rounded bg-gray-900 px-4 py-2 text-white disabled:cursor-not-allowed disabled:bg-gray-400"
                            :disabled="!canSend || !selectedSectionId"
                        >
                            Calculate
                        </button>

                        <button
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canSend || !selectedSectionId"
                            @click="requestHistory"
                        >
                            Refresh History
                        </button>

                        <button
                            type="button"
                            class="rounded border border-red-300 px-4 py-2 text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!canSend || !selectedSectionId || !hasHistory"
                            @click="clearHistory"
                        >
                            Clear Section History
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
                </form>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">{{ activeSection?.name ?? 'History' }}</h3>
                        <p class="text-xs text-gray-500">Last update: {{ formatTimestamp(stats.latest_calculated_at) }}</p>
                    </div>

                    <ul v-if="history.length" class="space-y-2">
                        <li
                            v-for="item in history"
                            :key="item.id"
                            class="rounded border border-gray-200 px-3 py-2 text-sm text-gray-700"
                        >
                            <div class="flex items-center justify-between gap-3">
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
                                    Use Result
                                </button>
                            </div>

                            <p v-if="item.note" class="mt-1 text-xs text-gray-500">Note: {{ item.note }}</p>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-gray-500">Bu bo'limda hali hisob-kitob yo'q.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
