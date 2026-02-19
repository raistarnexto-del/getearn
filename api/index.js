const { Telegraf } = require('telegraf');
const admin = require('firebase-admin');

// 1. إعداد Firebase باستخدام البيانات التي أرفقتها
// ملاحظة: يفضل وضع هذه البيانات في Vercel Environment Variables للأمان
const firebaseConfig = {
  projectId: "lolaminig-afea4",
  databaseURL: "https://lolaminig-afea4-default-rtdb.firebaseio.com"
};

if (!admin.apps.length) {
  admin.initializeApp({
    credential: admin.credential.cert({
      // ستحتاج لاستخراج ملف الـ JSON الخاص بـ Service Account من إعدادات فيربيز وضعه هنا
      // أو استخدام الوصول الافتراضي إذا كنت ترفع من بيئة مخولة
    }),
    databaseURL: firebaseConfig.databaseURL
  });
}

const db = admin.database();
const bot = new Telegraf(process.env.BOT_TOKEN);

// 2. نظام الربح والإحالة (Referral System)
bot.start(async (ctx) => {
  const userId = ctx.from.id;
  const referralId = ctx.startPayload; // معرف الشخص الذي دعا المستخدم
  const userRef = db.ref(`users/${userId}`);
  
  const snapshot = await userRef.once('value');
  if (!snapshot.exists()) {
    // إنشاء مستخدم جديد
    await userRef.set({
      username: ctx.from.username || "Guest",
      balance: 0,
      invitedBy: referralId || null,
      clicks: 0
    });

    // مكافأة الشخص الذي قام بالدعوة
    if (referralId && referralId != userId) {
      const inviterRef = db.ref(`users/${referralId}/balance`);
      await inviterRef.transaction((current) => (current || 0) + 50); // 50 نقطة لكل إحالة
    }
  }

  ctx.reply(`💰 مرحباً بك في منجم الأرباح!\n\nرصيدك الحالي: ${(snapshot.val()?.balance || 0)} نقطة`, {
    reply_markup: {
      inline_keyboard: [
        [{ text: "⛏️ ابدأ التعدين (إربح)", callback_data: "mine" }],
        [{ text: "👥 دعوة الأصدقاء", callback_data: "invite" }],
        [{ text: "📊 الإحصائيات", callback_data: "stats" }]
      ]
    }
  });
});

// 3. معالجة الأزرار
bot.on('callback_query', async (ctx) => {
  const userId = ctx.from.id;
  const action = ctx.callbackQuery.data;
  const userRef = db.ref(`users/${userId}`);

  if (action === 'mine') {
    const reward = Math.floor(Math.random() * 5) + 1;
    await userRef.child('balance').transaction((b) => (b || 0) + reward);
    await userRef.child('clicks').transaction((c) => (c || 0) + 1);
    
    ctx.answerCbQuery(`🎉 ربحت ${reward} نقطة!`);
    ctx.editMessageText(`✅ تمت عملية التعدين بنجاح!\nاستمر في الضغط لزيادة أرباحك.`);
  } 
  
  else if (action === 'invite') {
    const inviteLink = `https://t.me/${ctx.botInfo.username}?start=${userId}`;
    ctx.reply(`🔗 رابط الإحالة الخاص بك:\n${inviteLink}\n\nستحصل على 50 نقطة لكل صديق ينضم عبرك!`);
    ctx.answerCbQuery();
  }
});

// 4. التوافق مع Vercel Serverless
module.exports = async (req, res) => {
  try {
    if (req.method === 'POST') {
      await bot.handleUpdate(req.body);
      res.status(200).send('OK');
    } else {
      res.status(200).send('Bot is running...');
    }
  } catch (err) {
    console.error(err);
    res.status(500).send('Error');
  }
};
