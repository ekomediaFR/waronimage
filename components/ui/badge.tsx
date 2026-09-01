import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

// Google Material-style tonal chips.
const badgeVariants = cva(
  "inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium",
  {
    variants: {
      variant: {
        default: "border-zinc-800 bg-zinc-950 text-zinc-300",
        green: "border-emerald-700 bg-emerald-950 text-emerald-300",
        blue: "border-sky-700 bg-sky-950 text-sky-300",
        yellow: "border-amber-700 bg-amber-950 text-amber-300",
        red: "border-red-800 bg-red-950 text-red-300",
        amber: "border-sky-700 bg-sky-950 text-amber-500",
      },
    },
    defaultVariants: { variant: "default" },
  }
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
