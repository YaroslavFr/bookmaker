<template>
  <div>
    <h2 class="font-bold mb-10">Сделать демо-ставку</h2>
    <form @submit.prevent="submitAll" class="bet-form">
      <div class="row row-start mb-4">
        <label for="bettor_name" class="form-label">Имя игрока</label>
        <input type="text" id="bettor_name" placeholder="Например: Иван" v-model="bettorName" class="border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      
      <div class="row row-start mb-4">
        <div class="form-label">Купон</div>
        <ul id="slip-list">
          <li v-for="item in slip" :key="item.eventId" class="slip-item">
            <div class="row row-between">
              <div>
                <strong>{{ item.home && item.away ? `${item.home} vs ${item.away}` : `Event #${item.eventId}` }}</strong>
                <div class="muted">Исход: {{ selectionLabel(item.selection, item.home, item.away) }} • кэф {{ item.odds }}</div>
              </div>
              <button class="" type="button" @click="removeItem(item.eventId)">X</button>
            </div>
          </li>
        </ul>
        <div id="slip-empty" class="muted" v-show="slip.length === 0">
          Добавьте исходы, кликая по коэффициентам в таблице
        </div>
      </div>
      <div class="row row-start mb-4">
        <label for="amount_demo" class="form-label">Сумма (демо)</label>
        <input type="number" id="amount_demo" placeholder="Например: 100" v-model.number="amountDemo" class="border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
      </div>
      <div class="row">
        <button class="btn" type="button" @click="clearSlip" :disabled="slip.length === 0">Очистить</button>
        <button class="btn btn-primary" type="button" @click="submitAll" :disabled="disableSubmit">Поставить</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref, computed } from 'vue'

const bettorName = ref('')
const amountDemo = ref(null)
const slip = ref([]) // [{ eventId, home, away, selection, odds }]
const submitting = ref(false)

const selectionLabel = (sel, home, away) => {
  if (sel === 'home') return home || 'home'
  if (sel === 'away') return away || 'away'
  return 'draw'
}

function addOrReplaceSlipItem(item) {
  const idx = slip.value.findIndex(i => i.eventId === item.eventId)
  if (idx >= 0) slip.value[idx] = item
  else slip.value.push(item)
}

function removeItem(eventId) {
  slip.value = slip.value.filter(i => i.eventId !== eventId)
}

function clearSlip() {
  slip.value = []
}

const disableSubmit = computed(() => {
  return submitting.value || slip.value.length === 0 || !bettorName.value || !amountDemo.value
})

function handleOddClick(e) {
  const btn = e.target.closest('.odd-btn')
  if (!btn) return
  const eventId = btn.getAttribute('data-event-id')
  const selection = btn.getAttribute('data-selection')
  const home = btn.getAttribute('data-home')
  const away = btn.getAttribute('data-away')
  const odds = btn.getAttribute('data-odds')
  addOrReplaceSlipItem({ eventId, home, away, selection, odds })
}

async function submitAll() {
  if (disableSubmit.value) return
  submitting.value = true
  try {
    const rootEl = document.getElementById('vue-app')
    const csrfToken = rootEl?.dataset?.csrf || ''
    const postUrl = rootEl?.dataset?.postUrl || ''
    for (const item of slip.value) {
      const body = new URLSearchParams()
      body.append('bettor_name', bettorName.value)
      body.append('amount_demo', String(amountDemo.value))
      body.append('event_id', String(item.eventId))
      body.append('selection', item.selection)
      body.append('_token', csrfToken)
      const res = await fetch(postUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body,
      })
      if (!res.ok) {
        const text = await res.text()
        throw new Error('Ошибка ставки: ' + text)
      }
    }
    clearSlip()
    location.reload()
  } catch (e) {
    alert(e.message)
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleOddClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOddClick)
})
</script>

<style scoped>
/* Дополнительные стили компонента при необходимости */
</style>