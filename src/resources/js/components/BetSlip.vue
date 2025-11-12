<template>
  <div>
    <h2 class="font-bold md:mb-8 mb-3">Сделать демо-ставку</h2>
    <form @submit.prevent="submitAll" class="bet-form">
      <!-- Общая ошибка с плейсхолдером -->
      <div class="general-error" :class="errors.general ? 'general-error--visible' : 'general-error--placeholder'">{{ errors.general || ' ' }}</div>
      
      <div class="row row-start" v-if="!isAuth">
        <label for="bettor_name" class="form-label">Имя игрока</label>
        <div>
          <input type="text" id="bettor_name" placeholder="Например: Иван" v-model="bettorName"
                :class="['border rounded-md px-3 py-2 focus:outline-none', errors.name ? 'border-red-500 focus:ring-2 focus:ring-red-500' : 'border-gray-300 focus:ring-2 focus:ring-blue-500']" />
          <p class="form-hint" :class="{ 'form-hint--error': !!errors.name, 'form-hint--empty': !errors.name }">{{ errors.name || ' ' }}</p>
        </div>
      </div>
      <div class="row row-start" v-else>
        <div class="form-label">Логин</div>
        <div>
          <div class="font-bold px-3 py-0">{{ bettorName }}</div>
          <p class="form-hint form-hint--empty"> </p>
        </div>
      </div>
      
      <div class="row row-start flex-col">
        <div class="form-label">Купон</div>
        <div class="w-full">
        <ul id="slip-list">
          <li v-for="item in slip" :key="item.eventId" class="slip-item">
            <div class="row row-between">
              <div class="w-full flex justify-between items-start">
                <div>
                  <strong>{{ item.home && item.away ? `${item.home} vs ${item.away}` : `Event #${item.eventId}` }}</strong>
                  <div class="muted">Исход: <span v-if="item.market" class="market-title">{{ item.market }}</span><span v-if="item.market"> — </span><span class="market-sel">{{ selectionLabel(item.selection, item.home, item.away) }}</span> • кэф <span class="text-orange-400 text-base">{{ item.odds }}</span></div>
                </div>
                <button class="font-bold" type="button" @click="removeItem(item.eventId)">X</button>
              </div>
            </div>
          </li>
        </ul>
        <div id="slip-empty" class="muted" v-show="slip.length === 0">
          Добавьте исходы, кликая по коэффициентам в таблице
        </div>
        <p class="form-hint" :class="{ 'form-hint--error': !!errors.slip, 'form-hint--empty': !errors.slip }">{{ errors.slip || ' ' }}</p>
        </div>
      </div>
      <div class="row row-start">
        <label for="amount_demo" class="form-label">Сумма (демо)</label>
        <div>
        <input type="number" id="amount_demo" placeholder="Например: 100" v-model.number="amountDemo"
               :class="['border rounded-md px-3 py-2 focus:outline-none', errors.amount ? 'border-red-500 focus:ring-2 focus:ring-red-500' : 'border-gray-300 focus:ring-2 focus:ring-blue-500']" />
        <p class="form-hint" :class="{ 'form-hint--error': !!errors.amount, 'form-hint--empty': !errors.amount }">{{ errors.amount || ' ' }}</p>
      </div>
      </div>
      <div class="row mt-6">
        <button class="btn btn-primary" type="button" @click="submitAll" :disabled="disableSubmit">Поставить</button>
        <button class="btn" type="button" @click="clearSlip" :disabled="slip.length === 0">Очистить</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref, computed } from 'vue'

const bettorName = ref('')
const isAuth = ref(false)
const amountDemo = ref(null)
const slip = ref([]) // [{ eventId, home, away, selection, odds }]
const submitting = ref(false)
const errors = ref({ name: null, amount: null, slip: null, general: null })

const selectionLabel = (sel, home, away) => {
  if (sel === 'home') return home || 'home'
  if (sel === 'away') return away || 'away'
  if (sel === 'draw') return 'draw'
  // Для доп. рынков показываем метку селекции как есть
  return sel
}

function addOrReplaceSlipItem(item) {
  // Разрешаем несколько матчей в купоне. Для одного матча храним один исход.
  const idx = slip.value.findIndex(i => i.eventId === item.eventId)
  if (idx >= 0) {
    slip.value[idx] = item
  } else {
    slip.value.push(item)
  }
}

function removeItem(eventId) {
  slip.value = slip.value.filter(i => i.eventId !== eventId)
}

function clearSlip() {
  slip.value = []
}

function clearErrors() {
  errors.value = { name: null, amount: null, slip: null, general: null }
}

function validateForm() {
  clearErrors()
  let ok = true
  if (!isAuth.value) {
    if (!bettorName.value || bettorName.value.trim().length === 0) {
      errors.value.name = 'Вы не заполнили имя игрока'
      ok = false
    }
  }
  if (!amountDemo.value || Number(amountDemo.value) <= 0) {
    errors.value.amount = 'Введите сумму ставки'
    ok = false
  }
  if (slip.value.length === 0) {
    errors.value.slip = 'Вы не выбрали ни одного исхода'
    ok = false
  }
  return ok
}

const disableSubmit = computed(() => {
  // Блокируем кнопку только во время отправки, чтобы показать ошибки при клике
  return submitting.value
})

function handleOddClick(e) {
  const btn = e.target.closest('.odd-btn')
  if (!btn) return
  const eventId = btn.getAttribute('data-event-id')
  const market = btn.getAttribute('data-market')
  const selection = btn.getAttribute('data-selection')
  const home = btn.getAttribute('data-home')
  const away = btn.getAttribute('data-away')
  const odds = btn.getAttribute('data-odds')
  addOrReplaceSlipItem({ eventId, home, away, selection, odds, market })
}

async function submitAll() {
  if (submitting.value) return
  // Клиентская валидация
  if (!validateForm()) {
    return
  }
  submitting.value = true
  try {
    const rootEl = document.getElementById('vue-app')
    const csrfToken = rootEl?.dataset?.csrf || ''
    const postUrl = rootEl?.dataset?.postUrl || ''

    const items = slip.value.map(i => ({ event_id: Number(i.eventId), selection: i.selection, odds: Number(i.odds), market: i.market }))
    const payload = {
      bettor_name: bettorName.value,
      amount_demo: Number(amountDemo.value),
      items,
    }

    const res = await fetch(postUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    })

    if (!res.ok) {
      const text = await res.text()
      errors.value.general = 'Ошибка ставки: ' + text
      return
    }

    clearSlip()
    clearErrors()
    location.reload()
  } catch (e) {
    errors.value.general = e.message
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleOddClick)
  const rootEl = document.getElementById('vue-app')
  const authFlag = rootEl?.dataset?.isAuth === '1'
  const username = rootEl?.dataset?.username || ''
  isAuth.value = !!authFlag
  if (isAuth.value && username) {
    bettorName.value = username
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOddClick)
})
</script>

<style scoped>
/* Дополнительные стили компонента при необходимости */
.form-hint {
  min-height: 30px;
  border-radius: 0.375rem;
  padding: 0.3rem 0;
}
.general-error--placeholder {
  visibility: hidden;
}
.general-error--visible {
  visibility: visible;
  background-color: #fef2f2; /* red-50 */
  border: 1px solid #fecaca; /* red-200 */
  color: #b91c1c; /* red-700 */
}

/* Резервируем отступ под сообщение ошибки именно под блоком slip-empty */
#slip-empty {
  margin-bottom: 1.25rem; /* совпадает с высотой сообщения об ошибке */
}

.form-hint {
  font-size: 0.875rem; /* text-sm */
  margin-top: 0; /* чтобы не добавлять лишний сдвиг */
}
.form-hint--empty {
  visibility: hidden; /* скрываем хинт полностью, не влияя на раскладку */
  opacity: 0;
}
.form-hint--error {
  color: #dc2626; /* text-red-600 */
}
</style>