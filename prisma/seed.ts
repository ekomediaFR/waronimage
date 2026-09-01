/** Seed the image style library with the default styles. Run: npx tsx prisma/seed.ts */
import { config } from "dotenv";
config({ path: ".env.local" });
config();

import { PrismaClient } from "@prisma/client";
import { DEFAULT_STYLES } from "../lib/styleSeeds";

const prisma = new PrismaClient();

async function main() {
  const count = await prisma.imageStyle.count();
  if (count > 0) {
    console.log(`Style library already has ${count} styles — skipping seed.`);
    return;
  }
  await prisma.imageStyle.createMany({ data: DEFAULT_STYLES });
  console.log(`Seeded ${DEFAULT_STYLES.length} image styles.`);
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(() => prisma.$disconnect());
