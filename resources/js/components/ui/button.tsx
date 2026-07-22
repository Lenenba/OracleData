import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded text-[13px] font-semibold transition-[color,background-color,border-color,box-shadow] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring/25 aria-invalid:ring-destructive/20 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "border border-primary bg-primary text-primary-foreground shadow-xs hover:border-[#1e5dab] hover:bg-[#1e5dab]",
        destructive:
          "border border-destructive bg-destructive text-white shadow-xs hover:border-[#d24a6b] hover:bg-[#d24a6b] focus-visible:ring-destructive/20",
        outline:
          "border border-input bg-card text-foreground shadow-xs hover:border-[#a1a9b1] hover:bg-muted",
        secondary:
          "border border-secondary bg-secondary text-secondary-foreground shadow-xs hover:border-[#dfe5ed] hover:bg-[#dfe5ed] dark:hover:bg-[#30313c]",
        ghost: "border border-transparent hover:bg-muted hover:text-primary",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default: "h-[37px] px-4 py-2 has-[>svg]:px-3",
        sm: "h-[31px] px-3 text-xs has-[>svg]:px-2.5",
        lg: "h-[43px] px-6 text-sm has-[>svg]:px-4",
        icon: "size-[37px]",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
