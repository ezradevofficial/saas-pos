// How to reach each POS screen the manual shows. Each recipe signs in as a demo user, gets the
// app to the screen named by the topic, and leaves it there; manual-pos-screenshots.mjs takes the picture.
// Labels come from pos/src/locales (h.t), so one recipe serves English and French.

/** The visible text of a button before its amount, for example "Charge" from "Charge KES 270.00". */
const before = (h, key) => h.t(key, { amount: '\u0000', total: '\u0000' }).split('\u0000')[0].trim()
const starts = (h, key) => new RegExp(`^${before(h, key)}`)

/** A cashier with two products in the sale, on the sell screen. */
const withSale = async (h) => {
  await h.sell()
  await h.tile('Bottled water 500 ml')
  await h.tile('Bar soap')
}

const toPayment = async (h) => {
  await withSale(h)
  await h.click(starts(h, 'ds.saleTotal.pay'))
  await h.wait(900)
}

/** A completed cash sale, on the receipt screen. */
const toReceipt = async (h) => {
  await toPayment(h)
  await h.click(starts(h, 'pos.pay.exact'))
  await h.click(h.t('pos.pay.complete'))
  await h.wait(1500)
}

const openMenu = async (h) => {
  await h.click(h.t('pos.menu.title'))
}

/** The till menu entry, after signing in and opening a shift. */
const fromMenu = (user, key) => async (h) => {
  await h.sell(user)
  await openMenu(h)
  await h.click(h.t(key))
  await h.wait(1200)
}

const salesList = async (h) => {
  await h.sell()
  await openMenu(h)
  await h.click(h.t('pos.menu.sales'))
  await h.wait(1500)
}

const FLOOR_USER = 'Demo waiter'

/** A restaurant order on a table the waiter already has open. */
const openTable = (table) => async (h) => {
  await h.signIn(FLOOR_USER)
  await h.openShift()
  await h.page.getByText(h.t('restaurant.filter.all'), { exact: true }).first().click()
  await h.wait(500)
  await h.page.getByRole('button', { name: new RegExp(`^${table}\\b`) }).first().click()
  await h.wait(1200)
}

/** The forecourt home with a fuel shift open (opened here when none is). */
const toForecourt = async (h) => {
  await fromMenu('Demo shift supervisor', 'fuel.menu.forecourt')(h)
  const open = h.page.getByRole('button', { name: h.t('fuel.home.open') })
  if (await open.count()) {
    await open.first().click()
    await h.wait(800)
    await h.page.getByText(/P1-A/).first().click()
    await h.wait(500)
    await h.page.getByLabel(h.t('fuel.open.reading')).first().fill('1100')
    await h.page.getByRole('button', { name: h.t('fuel.open.submit') }).last().click()
    await h.wait(1500)
  }
}

/** The van rep's route-day screen (menu entry Route day), before the day starts. */
const vanDay = fromMenu('Demo van rep', 'vansales.menu.day')

/** The route day started, on the stops list. */
const vanStarted = async (h) => {
  await vanDay(h)
  const route = h.page.getByRole('radio', { name: /Nairobi west/ })
  if (await route.count()) {
    await route.first().click()
    await h.wait(500)
    await h.page.getByText(new RegExp(h.t('vansales.day.start'))).last().click()
    await h.wait(1800)
  }
}

export const recipes = [
  {
    id: 'pos-sign-in',
    run: async (h) => {
      await h.page.reload({ waitUntil: 'networkidle' })
      await h.page.getByText(h.t('signIn.title')).waitFor()
      await h.wait(800)
    },
  },
  { id: 'pos-sell', run: withSale },
  { id: 'pos-sync-status', run: withSale },
  {
    id: 'pos-work-offline',
    run: async (h) => {
      await withSale(h)
      await h.context.setOffline(true)
      await h.wait(2500)
    },
  },
  {
    id: 'pos-give-discount',
    run: async (h) => {
      await withSale(h)
      await h.page.getByText('Bottled water 500 ml').last().click()
      await h.wait(800)
    },
  },
  { id: 'pos-take-cash-payment', run: toPayment },
  {
    id: 'pos-take-mpesa-payment',
    run: async (h) => {
      await toPayment(h)
      await h.click(/M-Pesa/)
    },
  },
  { id: 'pos-receipt', run: toReceipt },
  { id: 'pos-pay-cash-in-out', run: fromMenu('Demo cashier', 'pos.menu.cash') },
  { id: 'pos-refund-sale', run: salesList },
  { id: 'pos-void-sale', run: salesList },
  {
    id: 'pos-manager-approval',
    run: async (h) => {
      await salesList(h)
      await h.page.getByRole('button', { name: /R-/ }).first().click()
      await h.wait(1000)
      await h.page.getByRole('button', { name: new RegExp(h.t('pos.sales.returnMore', { name: 'Bottled water 500 ml' })) }).first().click()
      await h.page.getByLabel(h.t('pos.sales.reason')).fill('Customer changed their mind')
      await h.click(starts(h, 'pos.sales.refund'))
      await h.wait(1000)
    },
  },
  {
    id: 'pos-close-shift',
    run: async (h) => {
      await h.sell()
      await h.click(h.t('pos.header.closeShift'))
      await h.wait(900)
    },
  },
  {
    id: 'restaurant-open-table-or-tab',
    run: async (h) => {
      await h.signIn(FLOOR_USER)
      await h.openShift()
      await h.page.getByText(h.t('restaurant.filter.all'), { exact: true }).first().click()
      await h.wait(700)
    },
  },
  {
    id: 'restaurant-send-to-kitchen',
    run: async (h) => {
      await openTable('T2')(h)
      const open = h.page.getByRole('button', { name: h.t('restaurant.open.table') })
      if (await open.count()) await h.click(h.t('restaurant.open.table'))
      await h.wait(1200)
      await h.tile('Nyama choma')
      await h.tile('Tusker')
    },
  },
  { id: 'restaurant-change-order', run: openTable('T1') },
  {
    id: 'restaurant-bill-and-pay',
    run: async (h) => {
      await openTable('T1')(h)
      await h.click(h.t('restaurant.bill.open'))
      await h.wait(1200)
    },
  },
  // Fuel: the forecourt home, opening a shift, a dip and a fuel sale (menu entry Forecourt).
  { id: 'fuel-open-and-close-shift', run: async (h) => {
    await fromMenu('Demo shift supervisor', 'fuel.menu.forecourt')(h)
    await h.click(h.t('fuel.home.open'))
  } },
  { id: 'fuel-record-a-dip', run: async (h) => {
    await toForecourt(h)
    await h.click(h.t('fuel.home.dip'))
  } },
  { id: 'fuel-sell-fuel', run: async (h) => {
    await toForecourt(h)
    await h.click(h.t('fuel.home.sell'))
    await h.page.getByText(/P1-A/).first().click()
    await h.wait(700)
  } },
  // Not a topic: closes the fuel shift again, so the next run meets the open-shift screen.
  { id: '_fuel-cleanup', save: false, run: async (h) => {
    await toForecourt(h)
    await h.page.getByRole('button', { name: h.t('fuel.home.close') }).last().click()
    await h.wait(900)
    await h.page.getByLabel(h.t('fuel.close.reading')).first().fill('1100')
    await h.page.getByRole('button', { name: h.t('fuel.close.submit') }).last().click()
    await h.wait(1500)
  } },
  // Salon: the day's appointments and the walk-in queue.
  { id: 'salon-run-your-appointments', run: async (h) => {
    await fromMenu('Demo stylist', 'salon.menu.day')(h)
    // The demo's appointments are on the next Tuesday.
    const tuesday = new Date()
    tuesday.setDate(tuesday.getDate() + ((9 - tuesday.getDay()) % 7 || 7))
    const label = new Intl.DateTimeFormat(h.locale, { weekday: 'short' }).format(tuesday).replace('.', '')
    await h.page.getByText(new RegExp(`^${label}`, 'i')).first().click()
    await h.wait(900)
  } },
  { id: 'salon-walk-ins-and-queue', run: async (h) => {
    await fromMenu('Demo salon receptionist', 'salon.menu.queue')(h)
    await h.click(h.t('salon.queue.add'))
  } },
  // Van sales: the route day, load sheets, a stop and the end-of-day count.
  { id: 'van-start-the-day', till: 'van', run: vanDay },
  { id: 'van-serve-a-customer', till: 'van', run: async (h) => {
    await vanStarted(h)
    await h.click(h.t('vansales.day.visitOther'))
    await h.wait(900)
    await h.page.getByLabel(h.t('vansales.day.otherSearch')).fill('Mama')
    await h.wait(1200)
    await h.page.getByText('Mama Njeri Kiosk').first().click()
    await h.wait(1200)
  } },
  { id: 'van-count-the-van', till: 'van', run: async (h) => {
    await vanStarted(h)
    await h.click(h.t('vansales.day.endDay'))
    await h.wait(1200)
  } },
  // Pharmacy: the till with medicines.
  { id: 'pharmacy-dispense-at-till', run: async (h) => {
    await h.sell('Demo pharmacist')
  } },
  // Last: the shift is closed, so the next sign-in meets the open-shift screen.
  {
    id: 'pos-open-shift',
    run: async (h) => {
      await h.signIn('Demo cashier')
      const float = h.page.getByLabel(h.t('pos.shift.float', { currency: 'KES' }))
      if (!(await float.count())) {
        await h.click(h.t('pos.header.closeShift'))
        await h.page.getByLabel(h.t('pos.shift.countedIn', { currency: 'KES' })).fill('5000')
        await h.click(h.t('pos.shift.close'), { exact: true })
        await h.wait(1500)
        await h.signIn('Demo cashier')
      }
      await float.fill('5000')
    },
  },
]
