import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 disabled:pointer-events-none disabled:opacity-50",
  {
    variants: {
      variant: {
        default: "bg-amber-500 text-zinc-950 hover:bg-amber-400 font-semibold",
        secondary: "bg-zinc-800 text-zinc-100 hover:bg-zinc-700 border border-zinc-700",
        ghost: "text-zinc-300 hover:bg-zinc-800 hover:text-zinc-100",
        destructive: "bg-red-900/60 text-red-200 border border-red-800 hover:bg-red-900",
        success: "bg-emerald-900/60 text-emerald-200 border border-emerald-800 hover:bg-emerald-900",
      },
      size: {
        default: "h-9 px-4 py-2",
        sm: "h-7 rounded px-2.5 text-xs",
        lg: "h-10 rounded-md px-6",
      },
    },
    defaultVariants: { variant: "default", size: "default" },
  }
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, ...props }, ref) => (
    <button className={cn(buttonVariants({ variant, size, className }))} ref={ref} {...props} />
  )
);
Button.displayName = "Button";

export { Button, buttonVariants };
