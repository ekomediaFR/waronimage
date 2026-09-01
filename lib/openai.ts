import OpenAI from "openai";

let client: OpenAI | null = null;

export function hasOpenAI(): boolean {
  return Boolean(process.env.OPENAI_API_KEY);
}

/** Lazy OpenAI client — throws only when actually used without a key. */
export function getOpenAI(): OpenAI {
  if (!process.env.OPENAI_API_KEY) {
    throw new Error("OPENAI_API_KEY is not set. Add it to .env.local to enable AI features.");
  }
  if (!client) client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });
  return client;
}

/** Call GPT-4o with a prompt expected to return a strict JSON object. */
export async function chatJSON<T>(system: string, user: string, imageUrl?: string): Promise<T> {
  const openai = getOpenAI();
  const content: OpenAI.Chat.Completions.ChatCompletionContentPart[] = [{ type: "text", text: user }];
  if (imageUrl) content.push({ type: "image_url", image_url: { url: imageUrl } });

  const res = await openai.chat.completions.create({
    model: process.env.OPENAI_CHAT_MODEL || "gpt-4o",
    response_format: { type: "json_object" },
    messages: [
      { role: "system", content: system },
      { role: "user", content },
    ],
    temperature: 0.4,
  });
  const raw = res.choices[0]?.message?.content ?? "{}";
  return JSON.parse(raw) as T;
}
