<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Import — {{ account.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Result flash -->
                <div v-if="result" :class="result.errors.length ? 'bg-yellow-50 border-yellow-300' : 'bg-green-50 border-green-300'"
                     class="border rounded-lg p-4 text-sm">
                    <p class="font-medium">
                        Import complete: {{ result.inserted }} inserted, {{ result.skipped }} skipped.
                    </p>
                    <ul v-if="result.errors.length" class="mt-2 list-disc list-inside text-yellow-800">
                        <li v-for="err in result.errors" :key="err">{{ err }}</li>
                    </ul>
                </div>

                <!-- Watermark -->
                <div v-if="watermark" class="text-sm text-gray-500">
                    Last import covered up to <strong>{{ watermark }}</strong>.
                </div>

                <!-- Transactions upload -->
                <div class="bg-white shadow rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Transactions file</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        The trades export from DEGIRO (contains buys &amp; sells).
                        Re-uploading is safe — duplicates are skipped automatically.
                    </p>
                    <form @submit.prevent="uploadTransactions" enctype="multipart/form-data">
                        <div class="flex items-center gap-3">
                            <input type="file" accept=".csv,text/csv" @change="txFile = $event.target.files[0]"
                                   class="block text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3
                                          file:rounded file:border-0 file:text-sm file:font-medium
                                          file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer" />
                            <PrimaryButton type="submit" :disabled="!txFile || txUploading">
                                {{ txUploading ? 'Importing…' : 'Import' }}
                            </PrimaryButton>
                        </div>
                        <p v-if="txError" class="mt-2 text-sm text-red-600">{{ txError }}</p>
                    </form>
                </div>

                <!-- Account / ledger upload -->
                <div class="bg-white shadow rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-1">Account ledger file</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        The account export from DEGIRO (contains dividends, deposits, withdrawals, fees).
                        Re-uploading is safe — duplicates are skipped automatically.
                    </p>
                    <form @submit.prevent="uploadAccount" enctype="multipart/form-data">
                        <div class="flex items-center gap-3">
                            <input type="file" accept=".csv,text/csv" @change="acFile = $event.target.files[0]"
                                   class="block text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3
                                          file:rounded file:border-0 file:text-sm file:font-medium
                                          file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer" />
                            <PrimaryButton type="submit" :disabled="!acFile || acUploading">
                                {{ acUploading ? 'Importing…' : 'Import' }}
                            </PrimaryButton>
                        </div>
                        <p v-if="acError" class="mt-2 text-sm text-red-600">{{ acError }}</p>
                    </form>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps({
    account:   { type: Object, required: true },
    watermark: { type: String, default: null },
})

const page = usePage()
const result = ref(page.props.flash?.import_result ?? null)

const txFile     = ref(null)
const txUploading = ref(false)
const txError    = ref(null)

const acFile     = ref(null)
const acUploading = ref(false)
const acError    = ref(null)

function uploadTransactions() {
    if (!txFile.value) return
    txUploading.value = true
    txError.value = null
    const data = new FormData()
    data.append('csv', txFile.value)
    router.post(route('accounts.import.transactions', props.account.id), data, {
        onSuccess: (page) => {
            result.value = page.props.flash?.import_result ?? null
            txFile.value = null
        },
        onError: (errors) => { txError.value = errors.csv ?? 'Upload failed.' },
        onFinish: () => { txUploading.value = false },
    })
}

function uploadAccount() {
    if (!acFile.value) return
    acUploading.value = true
    acError.value = null
    const data = new FormData()
    data.append('csv', acFile.value)
    router.post(route('accounts.import.account', props.account.id), data, {
        onSuccess: (page) => {
            result.value = page.props.flash?.import_result ?? null
            acFile.value = null
        },
        onError: (errors) => { acError.value = errors.csv ?? 'Upload failed.' },
        onFinish: () => { acUploading.value = false },
    })
}
</script>
