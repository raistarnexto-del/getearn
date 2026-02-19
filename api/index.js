const { Telegraf } = require('telegraf');
const admin = require('firebase-admin');

// --- إعدادات البوت والقاعدة (ضع بياناتك هنا مباشرة) ---
const BOT_TOKEN = '8505457388:AAGZSyQjXYpBNO5ED0O3XMg6dF6vkKpwnis';
const FIREBASE_DB_URL = 'https://lolaminig-afea4-default-rtdb.firebaseio.com';

// إعداد Firebase (بما أنك تريدها في ملف واحد وبدون متغيرات بيئة)
// ملاحظة: إذا واجهت مشكلة في الصلاحيات، تأكد أن قواعد Firebase عندك (Rules) مضبوطة على true
if (!admin.apps.length) {
    admin.initializeApp({
        databaseURL: FIREBASE_DB_URL
    });
}

const db = admin.database();
const bot = new Telegraf(BOT_TOKEN);

// --- إعدادات الربح (نظام صعب لزيادة أرباحك) ---
const MINING_REWARD = 0.01; // ربح الضغطة الواحدة (قليل جداً)
const REFERRAL_REWARD = 0.5; // ربح الإحالة
const MIN_WITHDRAW = 100;    // الحد الأدنى للسحب

// --- منطق البوت ---

bot.start(async (ctx) => {
    const userId = ctx.from.id;
    const refBy = ctx.startPayload; // الشخص الذي دعا المستخدم
    const userRef = db.ref(`users/${userId}`);

    const snap = await userRef.once('value');
    if (!snap.exists()) {
        await userRef.set({
            name: ctx.from.first_name,
            balance: 0,
            invitedBy: refBy || null,
            clicks: 0,
            joinedAt: new Date().toISOString()
        });

        // إذا جاء عن طريق شخص آخر، نكافئ الداعي
        if (refBy && refBy != userId) {
            const inviterRef = db.ref(`users/${refBy}/balance`);
            await inviterRef.transaction(b => (b || 0) + REFERRAL_REWARD);
            // إشعار للداعي
            bot.telegram.sendMessage(refBy, `🔔 انضم صديق جديد عبر رابطك! حصلت على ${REFERRAL_REWARD} نقطة.`);
        }
    }

    ctx.reply(`💰 أهلاً بك في بوت التعدين الصعب!\n\nرصيدك: ${snap.val()?.balance || 0} نقطة\nالحد الأدنى للسحب: ${MIN_WITHDRAW} نقطة`, {
        reply_markup: {
            inline_keyboard: [
                [{ text: "⛏️ ابدأ التعدين (0.01 نقطة)", callback_data: "mine" }],
                [{ text: "👥 دعوة الأصدقاء", callback_data: "invite" }],
                [{ text: "💳 طلب سحب", callback_data: "withdraw" }]
            ]
        }
    });
});

bot.on('callback_query', async (ctx) => {
    const userId = ctx.from.id;
    const action = ctx.callbackQuery.data;
    const userRef = db.ref(`users/${userId}`);

    if (action === 'mine') {
        // تحديث الرصيد (نظام ضغطات بطيء)
        await userRef.transaction(user => {
            if (user) {
                user.balance = (user.balance || 0) + MINING_REWARD;
                user.clicks = (user.clicks || 0) + 1;
            }
            return user;
        });
        const snap = await userRef.once('value');
        ctx.answerCbQuery(`تم التعدين! رصيدك: ${snap.val().balance.toFixed(2)}`);
        ctx.editMessageText(`✅ رصيدك الحالي: ${snap.val().balance.toFixed(2)} نقطة\nعدد ضغطاتك: ${snap.val().clicks}`, {
            reply_markup: {
                inline_keyboard: [[{ text: "⛏️ اضغط مرة أخرى", callback_data: "mine" }]]
            }
        });
    }

    if (action === 'invite') {
        const link = `https://t.me/${ctx.botInfo.username}?start=${userId}`;
        ctx.reply(`🔗 رابط إحالتك:\n${link}\n\nاربح ${REFERRAL_REWARD} عن كل صديق!`);
        ctx.answerCbQuery();
    }

    if (action === 'withdraw') {
        const snap = await userRef.once('value');
        if (snap.val().balance < MIN_WITHDRAW) {
            ctx.answerCbQuery(`❌ رصيدك أقل من ${MIN_WITHDRAW}`, true);
        } else {
            ctx.reply("ارسِل عنوان محفظتك وفودافون كاش للتواصل مع الإدارة.");
            ctx.answerCbQuery();
        }
    }
});

// تشغيل الـ Webhook لفيرسل
module.exports = async (req, res) => {
    try {
        if (req.method === 'POST') {
            await bot.handleUpdate(req.body);
            res.status(200).send('OK');
        } else {
            res.status(200).send('Bot Status: Active');
        }
    } catch (e) {
        console.error(e);
        res.status(200).send('Error but suppressed'); // نرسل 200 لتجنب انهيار الـ Webhook
    }
};
